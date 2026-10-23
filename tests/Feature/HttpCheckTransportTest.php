<?php

declare(strict_types=1);

/** Verify actual pooled sockets, pinning, decoded response caps and timeout isolation on loopback only. */

use App\Contracts\Security\HostResolver;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorStatus;
use App\Services\Monitoring\Checks\HttpCheckCoordinator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\CheckInputFixture;
use Tests\Support\FakeHostResolver;

beforeEach(function (): void {
    $this->hits = tempnam(sys_get_temp_dir(), 'signalbox-check-hits-');
    $this->server = new Process([PHP_BINARY, base_path('tests/Fixtures/CheckLoopbackServer.php'), $this->hits], timeout: 15);
    $this->server->start();
    $ready = $this->server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY '));

    if (!$ready || preg_match('/READY 127\.0\.0\.1:(\d+)/', $this->server->getOutput(), $matches) !== 1) {
        throw new RuntimeException('Synthetic HTTP server did not start: '.$this->server->getErrorOutput());
    }

    $this->origin = 'http://pinned.checker.test:'.$matches[1];
    $this->app->instance(HostResolver::class, new FakeHostResolver(['pinned.checker.test' => ['127.0.0.1']]));
    config(['outbound-security.allowed_cidrs' => ['127.0.0.1/32']]);
    Http::allowStrayRequests([$this->origin.'/*']);
});
afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }

    if (isset($this->hits) && is_file($this->hits)) {
        unlink($this->hits);
    }
});

it('crosses a two-request barrier with mixed strategies proving real concurrent acquisition', function (): void {
    $results = app(TenantContext::class)->run(1, fn () => app(HttpCheckCoordinator::class)->run([
        CheckInputFixture::make(8, new HttpMonitorConfiguration($this->origin.'/barrier-http'), 2000),
        CheckInputFixture::make(2, new KeywordMonitorConfiguration(new HttpMonitorConfiguration($this->origin.'/barrier-keyword'), 'both-arrived'), 2000),
    ]));

    expect(array_keys($results))->toBe([8, 2])
        ->and($results[8]->status)->toBe(MonitorStatus::Up)
        ->and($results[2]->status)->toBe(MonitorStatus::Up)
        ->and($results[8]->latencyMs)->toBeGreaterThan(0)
        ->and($results[2]->latencyMs)->toBeGreaterThan(0);
    $hits = file($this->hits, FILE_IGNORE_NEW_LINES);
    sort($hits);
    expect($hits)->toBe(['/barrier-http', '/barrier-keyword']);
});

it('aborts a gzip response when decoded bytes exceed the cap', function (): void {
    $result = app(TenantContext::class)->run(1, fn () => app(HttpCheckCoordinator::class)->run([
        CheckInputFixture::make(1, new HttpMonitorConfiguration($this->origin.'/gzip'), 2000),
    ]))[1];

    expect($result->error)->toBe(CheckError::ResponseTooLarge)
        ->and(file($this->hits, FILE_IGNORE_NEW_LINES))->toBe(['/gzip']);
});

it('times out a hanging target once while another observation completes normally', function (): void {
    $results = app(TenantContext::class)->run(1, fn () => app(HttpCheckCoordinator::class)->run([
        CheckInputFixture::make(1, new HttpMonitorConfiguration($this->origin.'/hang'), 100),
        CheckInputFixture::make(2, new HttpMonitorConfiguration($this->origin.'/healthy'), 2000),
    ]));

    expect($results[1]->error)->toBe(CheckError::Timeout)
        ->and($results[1]->latencyMs)->toBeGreaterThan(0)
        ->and($results[2]->status)->toBe(MonitorStatus::Up);
    $hits = file($this->hits, FILE_IGNORE_NEW_LINES);
    sort($hits);
    expect($hits)->toBe(['/hang', '/healthy']);
});

it('refuses the loopback target without the explicit test exception before any socket is opened', function (): void {
    config(['outbound-security.allowed_cidrs' => []]);
    $result = app(TenantContext::class)->run(1, fn () => app(HttpCheckCoordinator::class)->run([
        CheckInputFixture::make(1, new HttpMonitorConfiguration($this->origin.'/healthy')),
    ]))[1];

    expect($result->error)->toBe(CheckError::BlockedAddress)
        ->and($result->latencyMs)->toBeNull()
        ->and(file_get_contents($this->hits))->toBe('');
});
