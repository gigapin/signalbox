<?php

declare(strict_types=1);

/** Verify pooled HTTP-family acquisition with deterministic DNS, clocks and HTTP fakes. */

use App\Contracts\Monitoring\CheckClock;
use App\Contracts\Monitoring\CheckWorkConsumer;
use App\Contracts\Security\HostResolver;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorStatus;
use App\Exceptions\MissingTenantContext;
use App\Services\Monitoring\Checks\HttpCheckCoordinator;
use App\Services\Tenancy\TenantContext;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckInputFixture;
use Tests\Support\FakeCheckClock;
use Tests\Support\FakeHostResolver;

beforeEach(function (): void {
    $this->clock = new FakeCheckClock();
    app()->instance(CheckClock::class, $this->clock);
    $this->dns = new FakeHostResolver([
        'checker.example.test' => ['93.184.216.34'],
        'other.example.test' => ['93.184.216.35'],
        'blocked.example.test' => ['127.0.0.1'],
    ]);
    app()->instance(HostResolver::class, $this->dns);
});

/**
 * @param list<App\Data\Monitoring\Checks\CheckInput> $inputs Trusted inputs for synthetic team one.
 * @return array<int, App\Data\Monitoring\Checks\CheckOutcome> Safe results keyed by execution.
 */
function runHttpChecks(array $inputs): array
{
    return app(TenantContext::class)->run(1, fn (): array => app(HttpCheckCoordinator::class)->run($inputs));
}

it('mixes HTTP and keyword checks in one pool with stable correlation and network timing', function (): void {
    Http::fake(function ($request, array $options) {
        $this->clock->now += 15;

        return Http::response('healthy', str_contains($request->url(), 'second') ? 201 : 200);
    });
    $results = runHttpChecks([
        CheckInputFixture::make(7),
        CheckInputFixture::make(3, new KeywordMonitorConfiguration(new HttpMonitorConfiguration('https://checker.example.test/second', expectedStatus: 201), 'healthy')),
    ]);

    expect(array_keys($results))->toBe([7, 3])
        ->and($results[7]->status)->toBe(MonitorStatus::Up)
        ->and($results[3]->status)->toBe(MonitorStatus::Up)
        ->and($results[7]->latencyMs)->toBe(15.0)
        ->and($results[3]->latencyMs)->toBe(15.0)
        ->and($results[3]->statusCode)->toBe(201);
    Http::assertSentCount(2);
});

it('validates an entire batch before any DNS lookup or HTTP request', function (string $case): void {
    Http::fake();
    $inputs = match ($case) {
        'timeout-small' => [CheckInputFixture::make(timeoutMs: 99)],
        'timeout-large' => [CheckInputFixture::make(timeoutMs: 10001)],
        'duplicate' => [CheckInputFixture::make(), CheckInputFixture::make()],
        'foreign' => [CheckInputFixture::make(), CheckInputFixture::make(2, teamId: 2)],
        'type' => [CheckInputFixture::make(), CheckInputFixture::make(2, new TlsMonitorConfiguration('example.test', 443))],
        'size' => array_map(fn (int $id) => CheckInputFixture::make($id), range(1, 11)),
    };

    expect(fn () => runHttpChecks($inputs))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
    expect($this->dns->queries)->toBeEmpty();
})->with(['duplicate', 'foreign', 'type', 'size', 'timeout-small', 'timeout-large']);

it('requires explicit tenant context and leaves the caller context intact', function (): void {
    Http::fake();
    expect(fn () => app(HttpCheckCoordinator::class)->run([CheckInputFixture::make()]))->toThrow(MissingTenantContext::class);
    expect(runHttpChecks([]))->toBe([]);
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('returns safe SSRF and resolution refusals before transport with nullable latency', function (string $url, CheckError $expected): void {
    Http::fake();
    $result = runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration($url))])[1];

    expect($result->error)->toBe($expected)
        ->and($result->latencyMs)->toBeNull()
        ->and($result->statusCode)->toBeNull()
        ->and(json_encode($result))->not->toContain('private-token', 'checker.example.test');
    Http::assertNothingSent();
})->with([
    ['http://127.0.0.1/?token=private-token', CheckError::BlockedAddress],
    ['http://localhost/', CheckError::LocalName],
    ['http://blocked.example.test/', CheckError::BlockedAddress],
    ['http://unresolvable.example.test/', CheckError::ResolutionFailed],
]);

it('sends exact configured methods and bodies through pinned non-proxying verified requests', function (HttpMethod $method): void {
    $body = in_array($method, [HttpMethod::GET, HttpMethod::HEAD], true) ? null : 'private-body';
    Http::fake(function ($request, array $options) use ($method, $body) {
        expect($request->method())->toBe($method->value)
            ->and($request->body())->toBe($body ?? '')
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['proxy'])->toBe('')
            ->and($options['verify'])->toBeTrue()
            ->and($options['cookies'])->toBeFalse()
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['checker.example.test:443:93.184.216.34'])
            ->and($options['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
            ->and($options['curl'][CURLOPT_FORBID_REUSE])->toBeTrue()
            ->and($options['timeout'])->toBe(1.0);

        return Http::response('', 204);
    });
    $result = runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration(
        'https://checker.example.test/health',
        $method,
        ['authorization' => 'private-token'],
        $body,
        204,
    ))])[1];
    expect($result->status)->toBe(MonitorStatus::Up);
    Http::assertSentCount(1);
})->with(HttpMethod::cases());

it('rewrites redirect methods and removes entity headers when the body is dropped', function (int $status, HttpMethod $method, HttpMethod $next): void {
    $seen = [];
    Http::fake(function ($request) use (&$seen, $status) {
        $seen[] = $request;

        return count($seen) === 1
            ? Http::response('redirect', $status, ['Location' => '/final'])
            : Http::response('healthy');
    });
    $body = $method === HttpMethod::HEAD ? null : 'secret-payload';
    $result = runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration(
        'https://checker.example.test/start',
        $method,
        ['content-type' => 'text/plain', 'authorization' => 'private-token'],
        $body,
        followRedirects: true,
    ))])[1];

    expect($result->status)->toBe(MonitorStatus::Up)
        ->and($seen[1]->method())->toBe($next->value)
        ->and($seen[1]->hasHeader('authorization', 'private-token'))->toBeTrue();

    if ($next === HttpMethod::GET) {
        expect($seen[1]->body())->toBe('')->and($seen[1]->hasHeader('content-type'))->toBeFalse();
    } else {
        expect($seen[1]->body())->toBe($body ?? '');
    }
})->with([
    [301, HttpMethod::POST, HttpMethod::GET], [302, HttpMethod::POST, HttpMethod::GET],
    [303, HttpMethod::PUT, HttpMethod::GET], [303, HttpMethod::HEAD, HttpMethod::HEAD],
    [307, HttpMethod::POST, HttpMethod::POST], [308, HttpMethod::PUT, HttpMethod::PUT],
    [301, HttpMethod::PUT, HttpMethod::PUT],
]);

it('never restores credentials after crossing origins or accepts implicit cookies', function (): void {
    $seen = [];
    Http::fake(function ($request) use (&$seen) {
        $seen[] = $request;

        return match (count($seen)) {
            1 => Http::response('', 302, ['Location' => 'https://other.example.test/second', 'Set-Cookie' => 'session=server-secret']),
            2 => Http::response('', 302, ['Location' => 'https://checker.example.test/final']),
            default => Http::response('healthy'),
        };
    });
    $result = runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration(
        'https://checker.example.test/start',
        headers: ['authorization' => 'secret', 'cookie' => 'private', 'x-custom' => 'credential'],
        followRedirects: true,
    ))])[1];

    expect($result->status)->toBe(MonitorStatus::Up);

    foreach (array_slice($seen, 1) as $request) {
        expect($request->hasHeader('authorization'))->toBeFalse()
            ->and($request->hasHeader('cookie'))->toBeFalse()
            ->and($request->hasHeader('x-custom'))->toBeFalse();
    }
});

it('refuses body disclosure and reauthorizes every redirect without contacting denied destinations', function (string $location, int $status, ?string $body, CheckError $expected): void {
    Http::fake(fn () => Http::response('', $status, ['Location' => $location]));
    $result = runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration(
        'https://checker.example.test/start',
        HttpMethod::POST,
        body: $body,
        followRedirects: true,
    ))])[1];

    expect($result->error)->toBe($expected);
    Http::assertSentCount(1);
})->with([
    ['https://other.example.test/final', 307, 'private-body', CheckError::CrossOriginBody],
    ['http://checker.example.test/final', 302, null, CheckError::RedirectDowngrade],
    ['https://blocked.example.test/final', 302, null, CheckError::BlockedAddress],
    ['', 302, null, CheckError::InvalidRedirect],
]);

it('keeps redirect responses observable when following is disabled and bounds enabled loops', function (): void {
    Http::fake(fn () => Http::response('', 302, ['Location' => '/again']));
    $plain = new HttpMonitorConfiguration('https://checker.example.test/start', expectedStatus: 302);
    expect(runHttpChecks([CheckInputFixture::make(configuration: $plain)])[1]->status)->toBe(MonitorStatus::Up);
    Http::assertSentCount(1);

    config(['outbound-security.max_redirects' => 2]);
    expect(runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration(
        'https://checker.example.test/start',
        followRedirects: true,
    ))])[1]->error)->toBe(CheckError::RedirectLimit);
    Http::assertSentCount(4);
});

it('shares measured time across redirect hops rather than restarting the timeout', function (): void {
    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'];
        $this->clock->now += count($timeouts) === 1 ? 600 : 400;

        return count($timeouts) === 1
            ? Http::response('', 302, ['Location' => '/final'])
            : Http::response('healthy');
    });
    $result = runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration(
        'https://checker.example.test/start',
        followRedirects: true,
    ))])[1];

    expect($timeouts)->toBe([1.0, 0.4])
        ->and($result->error)->toBe(CheckError::Timeout)
        ->and($result->latencyMs)->toBe(1000.0);
});

it('rejects oversized responses instead of accepting keyword absence on a truncated body', function (): void {
    config(['monitor-checks.max_response_bytes' => 5]);
    Http::fake(fn () => Http::response('123456'));
    $result = runHttpChecks([CheckInputFixture::make(configuration: new KeywordMonitorConfiguration(
        new HttpMonitorConfiguration('https://checker.example.test/'),
        'absent-secret',
        KeywordMode::Absent,
    ))])[1];

    expect($result->error)->toBe(CheckError::ResponseTooLarge);
});

it('applies the same response cap across all redirect bodies', function (): void {
    config(['monitor-checks.max_response_bytes' => 5]);
    Http::fakeSequence()->push('123', 302, ['Location' => '/final'])->push('456', 200);
    $result = runHttpChecks([CheckInputFixture::make(configuration: new HttpMonitorConfiguration(
        'https://checker.example.test/start',
        followRedirects: true,
    ))])[1];

    expect($result->error)->toBe(CheckError::ResponseTooLarge);
});

it('classifies transport errors safely without retrying or aborting other observations', function (int $errno, CheckError $expected): void {
    $attempts = [];
    Http::fake(function ($request) use ($errno, &$attempts) {
        $attempts[] = $request->url();

        if (str_contains($request->url(), 'failed')) {
            $error = new ConnectException('private-token https://checker.example.test/?token=private', new Request('GET', $request->url()), handlerContext: ['errno' => $errno]);

            return GuzzleHttp\Promise\Create::rejectionFor(new ConnectionException('private-token', previous: $error));
        }

        return Http::response('healthy');
    });
    $results = runHttpChecks([
        CheckInputFixture::make(1, new HttpMonitorConfiguration('https://checker.example.test/failed')),
        CheckInputFixture::make(2),
    ]);

    expect($results[1]->error)->toBe($expected)
        ->and($results[2]->status)->toBe(MonitorStatus::Up)
        ->and($results[1]->errorMessage())->not->toContain('private-token', 'checker.example.test');
    expect($attempts)->toHaveCount(2)
        ->and(count(array_filter($attempts, fn (string $url): bool => str_contains($url, 'failed'))))->toBe(1);
})->with([[28, CheckError::Timeout], [60, CheckError::Tls], [7, CheckError::Connection], [6, CheckError::ResolutionFailed]]);

it('propagates programming failures rather than manufacturing a target outage', function (): void {
    Http::fake(fn () => throw new LogicException('Synthetic programming error'));
    expect(fn () => runHttpChecks([CheckInputFixture::make()]))->toThrow(LogicException::class);
});

it('leaves scheduling disabled and the application consumer unbound', function (): void {
    expect(config('monitor-scheduling.enabled'))->toBeFalse()
        ->and(app()->bound(CheckWorkConsumer::class))->toBeFalse();
});
