<?php

declare(strict_types=1);

/** Exercise real TLS verification, concurrent sockets and bounded DNS UDP/TCP on synthetic loopback servers. */

use App\Contracts\Monitoring\TlsTransport;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Data\Security\AuthorizedDestination;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorStatus;
use App\Exceptions\CheckTransportFailed;
use App\Services\Monitoring\Checks\AsyncCheckRuntime;
use App\Services\Monitoring\Checks\NetworkCheckCoordinator;
use App\Services\Monitoring\Checks\ReactDnsTransport;
use App\Services\Monitoring\Checks\ReactTlsTransport;
use App\Services\Tenancy\TenantContext;
use React\Dns\Model\Message;
use React\EventLoop\LoopInterface;

use function React\Promise\all;

use React\Promise\PromiseInterface;
use Symfony\Component\Process\Process;
use Tests\Support\CheckInputFixture;
use Tests\Support\TlsCertificateFixture;

beforeEach(function (): void {
    $this->process = null;
    $this->certificates = null;
    $this->hits = tempnam(sys_get_temp_dir(), 'signalbox-network-hits-');
});

afterEach(function (): void {
    $this->process?->stop();
    $this->certificates?->cleanup();

    if (is_file($this->hits)) {
        unlink($this->hits);
    }
});

/**
 * @param string $script Synthetic server fixture filename.
 * @param list<string> $arguments Fixture-specific arguments, including owned temporary file paths.
 * @return array{Process, string} Running child and loopback endpoint.
 */
function startNetworkTransportProbe(string $script, array $arguments): array
{
    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/'.$script), ...$arguments], timeout: 15);
    $process->start();
    $ready = $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY '));

    if (!$ready || preg_match('/READY (127\.0\.0\.1:\d+)/', $process->getOutput(), $matches) !== 1) {
        $process->stop();
        throw new RuntimeException('Synthetic network server did not start: '.$process->getErrorOutput());
    }

    return [$process, $matches[1]];
}

it('verifies a real TLS certificate against a synthetic trust anchor while pinning the socket', function (): void {
    $this->certificates = new TlsCertificateFixture();
    [$this->process, $endpoint] = startNetworkTransportProbe('TlsLoopbackServer.php', [$this->certificates->valid, 'normal', $this->hits]);
    $destination = new AuthorizedDestination(null, 'tls.checker.test', (int) substr(strrchr($endpoint, ':'), 1), ['127.0.0.1']);
    $transport = new ReactTlsTransport($this->certificates->ca);
    $expiry = app(AsyncCheckRuntime::class)->await(fn (LoopInterface $loop): PromiseInterface => $transport->inspect($destination, 2000, $loop));

    expect($expiry->isFuture())->toBeTrue()
        ->and(file($this->hits, FILE_IGNORE_NEW_LINES))->toBe(['accepted']);
});

it('rejects real invalid chains, wrong hostnames and expired certificates', function (string $case): void {
    $this->certificates = new TlsCertificateFixture();
    [$this->process, $endpoint] = startNetworkTransportProbe('TlsLoopbackServer.php', [$case === 'expired' ? $this->certificates->expired : $this->certificates->valid, 'normal', $this->hits]);
    $host = $case === 'hostname' ? 'wrong.checker.test' : 'tls.checker.test';
    $destination = new AuthorizedDestination(null, $host, (int) substr(strrchr($endpoint, ':'), 1), ['127.0.0.1']);
    $transport = new ReactTlsTransport($case === 'chain' ? null : $this->certificates->ca);

    try {
        app(AsyncCheckRuntime::class)->await(fn (LoopInterface $loop): PromiseInterface => $transport->inspect($destination, 2000, $loop));
        test()->fail('An invalid synthetic certificate was accepted.');
    } catch (CheckTransportFailed $error) {
        expect($error->reason)->toBe(CheckError::Tls)
            ->and($error->getMessage())->not->toContain($host, $this->certificates->directory)
            ->and($error->getPrevious())->toBeNull();
    }
})->with(['chain', 'hostname', 'expired']);

it('crosses a real two-connection TLS barrier through the batch coordinator', function (): void {
    $this->certificates = new TlsCertificateFixture();
    [$this->process, $endpoint] = startNetworkTransportProbe('TlsLoopbackServer.php', [$this->certificates->valid, 'barrier', $this->hits]);
    $port = (int) substr(strrchr($endpoint, ':'), 1);
    app()->instance(TlsTransport::class, new ReactTlsTransport($this->certificates->ca));
    config(['outbound-security.allowed_cidrs' => ['127.0.0.1/32']]);

    $results = app(TenantContext::class)->run(1, fn (): array => app(NetworkCheckCoordinator::class)->run([
        CheckInputFixture::make(9, new TlsMonitorConfiguration('127.0.0.1', $port), 2000),
        CheckInputFixture::make(2, new TlsMonitorConfiguration('127.0.0.1', $port), 2000),
    ]));
    expect(array_keys($results))->toBe([9, 2])
        ->and($results[9]->status)->toBe(MonitorStatus::Up)
        ->and($results[2]->status)->toBe(MonitorStatus::Up)
        ->and($results[9]->latencyMs)->toBeGreaterThan(0)
        ->and(file($this->hits, FILE_IGNORE_NEW_LINES))->toHaveCount(2);
});

it('cancels a stalled TLS handshake on its deadline and opens no retry connection', function (): void {
    $this->certificates = new TlsCertificateFixture();
    [$this->process, $endpoint] = startNetworkTransportProbe('TlsLoopbackServer.php', [$this->certificates->valid, 'hang', $this->hits]);
    $destination = new AuthorizedDestination(null, 'tls.checker.test', (int) substr(strrchr($endpoint, ':'), 1), ['127.0.0.1']);

    try {
        app(AsyncCheckRuntime::class)->await(fn (LoopInterface $loop): PromiseInterface => (new ReactTlsTransport($this->certificates->ca))->inspect($destination, 100, $loop));
        test()->fail('A stalled handshake completed.');
    } catch (CheckTransportFailed $error) {
        expect($error->reason)->toBe(CheckError::Timeout)
            ->and(file($this->hits, FILE_IGNORE_NEW_LINES))->toBe(['accepted']);
    }
});

it('continues truncated UDP answers over TCP within one DNS observation', function (): void {
    [$this->process, $endpoint] = startNetworkTransportProbe('DnsLoopbackServer.php', [$this->hits]);
    $response = app(AsyncCheckRuntime::class)->await(fn (LoopInterface $loop): PromiseInterface => app(ReactDnsTransport::class)->query('tcp.example.test.', Message::TYPE_A, $endpoint, 1000, $loop));

    expect($response->answers[0]->data)->toBe('8.8.8.8')
        ->and(file($this->hits, FILE_IGNORE_NEW_LINES))->toBe(['udp tcp.example.test', 'tcp tcp.example.test']);
});

it('crosses a two-query DNS barrier and isolates a silent resolver response', function (): void {
    [$this->process, $endpoint] = startNetworkTransportProbe('DnsLoopbackServer.php', [$this->hits]);
    $responses = app(AsyncCheckRuntime::class)->await(function (LoopInterface $loop) use ($endpoint): PromiseInterface {
        $transport = app(ReactDnsTransport::class);

        return all([
            $transport->query('barrier-one.example.test.', Message::TYPE_A, $endpoint, 1000, $loop),
            $transport->query('barrier-two.example.test.', Message::TYPE_A, $endpoint, 1000, $loop),
            $transport->query('hang.example.test.', Message::TYPE_A, $endpoint, 100, $loop)->catch(static fn (CheckTransportFailed $error): CheckError => $error->reason),
        ]);
    });

    expect($responses[0]->answers[0]->data)->toBe('8.8.8.8')
        ->and($responses[1]->answers[0]->data)->toBe('8.8.8.8')
        ->and($responses[2])->toBe(CheckError::Timeout)
        ->and(file($this->hits, FILE_IGNORE_NEW_LINES))->toHaveCount(3);
});

it('classifies a refused TCP connection independently of TLS handshake failures', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $endpoint = stream_socket_get_name($listener, false);
    $port = (int) substr(strrchr($endpoint, ':'), 1);
    fclose($listener);
    $destination = new AuthorizedDestination(null, 'tls.checker.test', $port, ['127.0.0.1']);

    try {
        app(AsyncCheckRuntime::class)->await(fn (LoopInterface $loop): PromiseInterface => (new ReactTlsTransport())->inspect($destination, 1000, $loop));
        test()->fail('A closed loopback port unexpectedly accepted a connection.');
    } catch (CheckTransportFailed $error) {
        expect($error->reason)->toBe(CheckError::Connection);
    }
});
