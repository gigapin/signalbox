<?php

declare(strict_types=1);

/** Verify pure HTTP-family strategies and bounded decoded stream writes without Laravel or network access. */

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\Checks\HttpObservation;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\Monitoring\MonitorType;
use App\Services\Monitoring\Checks\BoundedResponseBuffer;
use App\Services\Monitoring\Checks\HttpStatusStrategy;
use App\Services\Monitoring\Checks\HttpStrategyResolver;
use App\Services\Monitoring\Checks\KeywordStrategy;
use App\Services\Monitoring\Checks\ResponseByteBudget;
use Carbon\CarbonImmutable;
use Tests\Support\CheckInputFixture;

it('compares exact HTTP status including expected error and redirect statuses', function (int $expected, int $actual, ?CheckError $error): void {
    $input = CheckInputFixture::make(configuration: new HttpMonitorConfiguration('https://example.test/', expectedStatus: $expected));
    $observation = new HttpObservation($actual, 'private server body', CarbonImmutable::now('UTC'), 12.5);
    $outcome = (new HttpStatusStrategy())->evaluate($input, $observation);

    expect($outcome->status)->toBe($error === null ? MonitorStatus::Up : MonitorStatus::Down)
        ->and($outcome->error)->toBe($error)
        ->and($outcome->latencyMs)->toBe(12.5)
        ->and($outcome->statusCode)->toBe($actual)
        ->and(json_encode($outcome))->not->toContain('private server body', 'example.test');
})->with([[200, 200, null], [404, 404, null], [302, 302, null], [200, 204, CheckError::UnexpectedStatus], [200, 503, CheckError::UnexpectedStatus]]);

it('evaluates exact keyword bytes with status failure precedence', function (string $body, string $keyword, KeywordMode $mode, int $status, ?CheckError $error): void {
    $input = CheckInputFixture::make(configuration: new KeywordMonitorConfiguration(new HttpMonitorConfiguration('https://example.test/'), $keyword, $mode));
    $outcome = (new KeywordStrategy())->evaluate($input, new HttpObservation($status, $body, CarbonImmutable::now('UTC'), null));

    expect($outcome->error)->toBe($error)->and($outcome->latencyMs)->toBeNull();
})->with([
    ['healthy', 'healthy', KeywordMode::Present, 200, null],
    ['Healthy', 'healthy', KeywordMode::Present, 200, CheckError::KeywordMismatch],
    ['', 'healthy', KeywordMode::Absent, 200, null],
    [' healthy ', ' healthy ', KeywordMode::Present, 200, null],
    ['a.b', 'a.b', KeywordMode::Present, 200, null],
    ['axb', 'a.b', KeywordMode::Present, 200, CheckError::KeywordMismatch],
    ["a\0b", "\0", KeywordMode::Present, 200, null],
    ['healthy', 'healthy', KeywordMode::Absent, 200, CheckError::KeywordMismatch],
    ['different', 'healthy', KeywordMode::Present, 500, CheckError::UnexpectedStatus],
]);

it('rejects unsupported and inconsistent configurations rather than defaulting to HTTP', function (): void {
    $resolver = new HttpStrategyResolver(new HttpStatusStrategy(), new KeywordStrategy());
    $inconsistent = new class () implements MonitorConfiguration {
        /** @return MonitorType Deliberately forged enum identity without the required concrete DTO. */
        public function type(): MonitorType
        {
            return MonitorType::Http;
        }
    };

    expect(fn () => $resolver->resolve(new TlsMonitorConfiguration('example.test', 443)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $resolver->resolve($inconsistent))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new HttpStatusStrategy())->evaluate(
        CheckInputFixture::make(configuration: new KeywordMonitorConfiguration(new HttpMonitorConfiguration('https://example.test/'), 'ok')),
        new HttpObservation(200, 'ok', CarbonImmutable::now(), 1),
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new KeywordStrategy())->evaluate(
        CheckInputFixture::make(),
        new HttpObservation(200, 'ok', CarbonImmutable::now(), 1),
    ))->toThrow(InvalidArgumentException::class);
});

it('bounds decoded stream writes exactly and cumulatively across redirect buffers', function (): void {
    $budget = new ResponseByteBudget(5);
    $first = new BoundedResponseBuffer($budget);
    expect(fwrite($first->sink(), '123'))->toBe(3)->and($first->contents())->toBe('123');
    $first->close();
    $second = new BoundedResponseBuffer($budget);
    expect(fwrite($second->sink(), '45'))->toBe(2)
        ->and($budget->exceeded)->toBeFalse();
    expect(fwrite($second->sink(), '6'))->toBeFalse()
        ->and($budget->exceeded)->toBeTrue()
        ->and($second->contents())->toBe('45');
    $second->close();
});

it('rejects invalid identity budget and normalized outcome fields', function (): void {
    expect(fn () => new CheckContext(0, 1, 1, MonitorRegion::Eu, CarbonImmutable::now()))->toThrow(InvalidArgumentException::class);
    expect(fn () => CheckInputFixture::make(timeoutMs: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ResponseByteBudget(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CheckOutcome(CheckInputFixture::make()->context, MonitorStatus::Down, CarbonImmutable::now(), null, null))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CheckOutcome(CheckInputFixture::make()->context, MonitorStatus::Up, CarbonImmutable::now(), -1, 200))->toThrow(InvalidArgumentException::class);
});
