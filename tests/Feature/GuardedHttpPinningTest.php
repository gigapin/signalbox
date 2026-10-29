<?php

declare(strict_types=1);

/**
 * Prove against a real loopback server that guarded requests connect only to pinned addresses.
 * The hostname exists only in the fake resolver, so success shows that system DNS was never consulted.
 */

use App\Contracts\Security\HostResolver;
use App\Enums\Security\OutboundRejection;
use App\Exceptions\OutboundDestinationRejected;
use App\Services\Security\GuardedHttpOptions;
use App\Services\Security\OutboundDestinationGuard;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

beforeEach(function (): void {
    $probe = stream_socket_server('tcp://127.0.0.1:0');

    if ($probe === false) {
        $this->markTestSkipped('A loopback TCP port is not available.');
    }

    $this->port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
    fclose($probe);

    $this->directory = sys_get_temp_dir().'/signalbox-pinning-'.bin2hex(random_bytes(6));
    mkdir($this->directory);
    file_put_contents(
        $this->directory.'/router.php',
        '<?php file_put_contents(__DIR__."/hits", $_SERVER["HTTP_HOST"]."\n", FILE_APPEND); echo $_SERVER["HTTP_HOST"];',
    );

    $this->server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $this->directory.'/router.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );

    // Bounded readiness wait for the child server; no request is sent before it accepts connections.
    $deadline = microtime(true) + 5;

    while (@fsockopen('127.0.0.1', $this->port, $code, $error, 0.1) === false) {
        if (microtime(true) > $deadline) {
            $this->markTestSkipped('The loopback PHP server did not start.');
        }

        usleep(20000);
    }

    $this->app->instance(HostResolver::class, new FakeHostResolver(['pinned.signalbox.test' => ['127.0.0.1']]));
});

afterEach(function (): void {
    if (isset($this->server) && is_resource($this->server)) {
        proc_terminate($this->server);
        proc_close($this->server);
    }

    foreach (glob($this->directory.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->directory);
    putenv('HTTP_PROXY');
});

it('connects to the pinned address for a name unknown to system DNS, ignoring environment proxies', function (): void {
    config(['outbound-security.allowed_cidrs' => ['127.0.0.1/32']]);
    putenv('HTTP_PROXY=http://127.0.0.1:9');

    // Only this synthetic origin bypasses the suite-wide stray request guard.
    Http::allowStrayRequests(["http://pinned.signalbox.test:{$this->port}/*"]);

    $destination = app(OutboundDestinationGuard::class)->authorizeUrl("http://pinned.signalbox.test:{$this->port}/probe?token=secret");
    $response = Http::withOptions(app(GuardedHttpOptions::class)->for($destination))->timeout(5)->get((string) $destination->url);

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe("pinned.signalbox.test:{$this->port}")
        ->and(file($this->directory.'/hits', FILE_IGNORE_NEW_LINES))->toBe(["pinned.signalbox.test:{$this->port}"]);
});

it('refuses the same loopback destination before connecting when no exception is configured', function (): void {
    config(['outbound-security.allowed_cidrs' => []]);

    try {
        app(OutboundDestinationGuard::class)->authorizeUrl("http://pinned.signalbox.test:{$this->port}/");
    } catch (OutboundDestinationRejected $exception) {
        expect($exception->reason)->toBe(OutboundRejection::BlockedAddress)
            ->and(file_exists($this->directory.'/hits'))->toBeFalse();

        return;
    }

    throw new LogicException('The loopback destination was authorized.');
});
