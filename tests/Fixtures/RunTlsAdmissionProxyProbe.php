<?php

declare(strict_types=1);

/** Validate the deployed Caddy template and exercise real proxy/PHP routing using only isolated local listeners. */

use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';

/**
 * @return list<int> Three distinct available loopback TCP ports; child processes bind them immediately afterward.
 * @throws RuntimeException When local listeners cannot be allocated.
 */
function admissionProbePorts(): array
{
    $sockets = [];
    $ports = [];

    try {
        foreach (range(1, 3) as $ignored) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);

            if ($socket === false) {
                throw new RuntimeException('Cannot allocate local proxy probe listeners.');
            }

            $sockets[] = $socket;
            $ports[] = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        }
    } finally {
        foreach ($sockets as $socket) {
            fclose($socket);
        }
    }

    return $ports;
}

/**
 * @param string $url Loopback URL whose path must reach the server without client normalization.
 * @param list<string> $headers Synthetic request fields, including an intentionally public fake secret.
 * @return int Actual HTTP response status; zero on readiness connection failures.
 */
function admissionProbeStatus(string $url, array $headers = []): int
{
    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Cannot create the local proxy probe client.');
    }

    try {
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 2000,
            CURLOPT_PROXY => '',
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json', ...$headers],
        ]);
        curl_exec($handle);

        return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($handle);
    }
}

if (getenv('RUN_DOCKER_TESTS') !== '1' || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Run this probe only in the isolated php-test service after database migrations.');
}

$binary = $argv[1] ?? '/tmp/caddy';
[$backendPort, $publicPort, $privatePort] = admissionProbePorts();
$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir().'/signalbox-tls-proxy-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$secret = str_repeat('a', 64);
$environment = [
    'ACME_EMAIL' => 'probe@example.com', 'APP_HOST' => 'app.example.com',
    'STATUS_DOMAIN_TLS_SECRET' => $secret, 'STATUS_DOMAIN_TLS_PEERS' => '127.0.0.1',
    'APP_URL' => 'http://localhost', 'APP_DEBUG' => '(false)',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'LOG_CHANNEL' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'XDG_DATA_HOME' => $directory.'/data', 'XDG_CONFIG_HOME' => $directory.'/config',
];
$children = [];

try {
    // Validate the unchanged production template first; adapt does not start listeners or issue certificates.
    (new Process([$binary, 'adapt', '--adapter', 'caddyfile', '--config', $root.'/docker/caddy/Caddyfile.example', '--validate'], $root, $environment, timeout: 15))->mustRun();
    $template = (string) file_get_contents($root.'/docker/caddy/Caddyfile.example');
    $runtime = str_replace([
        '127.0.0.1:8000', '127.0.0.1:9123', 'https:// {', "\ttls {\n\t\ton_demand\n\t}\n",
    ], [
        '127.0.0.1:'.$backendPort, '127.0.0.1:'.$privatePort, 'http://custom.example.com:'.$publicPort.' {', '',
    ], $template);
    $runtime = preg_replace('/^\{$/m', "{\n\tauto_https off\n\tadmin off", $runtime, 1);
    if (!is_string($runtime) || !str_contains($runtime, 'auto_https off')) {
        throw new RuntimeException('The proxy probe could not disable public TLS automation.');
    }
    file_put_contents($directory.'/Caddyfile', $runtime);
    $environment['APP_HOST'] = 'http://localhost:'.$publicPort;
    $backend = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$backendPort, '-t', $root.'/public', $root.'/tests/Fixtures/TlsAdmissionRouter.php'], $root, $environment, timeout: 60);
    $proxy = new Process([$binary, 'run', '--adapter', 'caddyfile', '--config', $directory.'/Caddyfile'], $root, $environment, timeout: 60);
    foreach ([$backend, $proxy] as $child) {
        $children[] = $child;
        $child->start();
    }

    $until = microtime(true) + 10;
    do {
        if (!$backend->isRunning() || !$proxy->isRunning()) {
            throw new RuntimeException('A local probe server exited: '.$backend->getErrorOutput().$proxy->getErrorOutput());
        }
        if (admissionProbeStatus('http://127.0.0.1:'.$privatePort.'/no-such-route') === 404) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $until);

    $checks = 0;
    $path = '/internal/status-domains/allow?domain=unclaimed-probe.example.com';
    foreach ([$path, '/index.php'.$path, '/internal/status-domains/%61llow?domain=unclaimed-probe.example.com'] as $target) {
        foreach ([[], ['SignalboxTlsSecret: '.$secret], ['SignalboxTlsSecret: wrong']] as $headers) {
            $expected = $headers === ['SignalboxTlsSecret: '.$secret] ? 404 : 403;
            if (admissionProbeStatus('http://127.0.0.1:'.$backendPort.$target, $headers) !== $expected) {
                throw new RuntimeException('The real PHP endpoint did not authenticate its routed path.');
            }
            $checks++;
        }
        foreach (['localhost', 'custom.example.com'] as $host) {
            if (admissionProbeStatus('http://127.0.0.1:'.$publicPort.$target, ['Host: '.$host, 'SignalboxTlsSecret: '.$secret]) !== 404) {
                throw new RuntimeException('The public Caddy ingress admitted a private TLS path.');
            }
            $checks++;
        }
    }
    foreach (['localhost', 'custom.example.com'] as $host) {
        if (admissionProbeStatus('http://127.0.0.1:'.$publicPort.'/_probe/header', ['Host: '.$host, 'SignalboxTlsSecret: '.$secret]) !== 204) {
            throw new RuntimeException('The public proxy forwarded the private credential header.');
        }
        $checks++;
    }
    if (admissionProbeStatus('http://127.0.0.1:'.$privatePort.$path) !== 404) {
        throw new RuntimeException('The private ask listener did not authenticate the request for an unclaimed domain.');
    }
    $checks++;
    fwrite(STDOUT, 'Production template validated; '.$checks." real proxy/PHP checks passed.\n");
} finally {
    foreach (array_reverse($children) as $child) {
        $child->stop();
    }
    // Caddy may create nested local state directories; remove only this probe's private temporary tree.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}
