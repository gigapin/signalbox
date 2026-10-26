<?php

declare(strict_types=1);

/** Verify deterministic epoch slots without Laravel or external services. */

use App\Services\Monitoring\Scheduling\MonitorSlots;
use Carbon\CarbonImmutable;

it('anchors repeatable slots with an unsigned CRC32 phase', function (int $interval): void {
    $slots = new MonitorSlots();
    $at = CarbonImmutable::parse('2026-10-25T02:15:43.999999+02:00');
    $latest = $slots->latest(123, $interval, $at);
    $next = $slots->next(123, $interval, $at);
    $phase = (int) sprintf('%u', crc32('123')) % $interval;

    expect($latest->getTimestamp() % $interval)->toBe($phase)
        ->and($latest->offset)->toBe(0)
        ->and($latest->lessThanOrEqualTo($at))->toBeTrue()
        ->and($next->isAfter($at))->toBeTrue()
        ->and($next->getTimestamp() - $latest->getTimestamp())->toBe($interval)
        ->and($slots->latest(123, $interval, $at->utc())->equalTo($latest))->toBeTrue();
})->with([30, 60, 300, 47]);

it('uses the current exact slot and always initializes strictly in the future', function (): void {
    $slots = new MonitorSlots();
    $boundary = $slots->next(7, 30, CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    expect($slots->latest(7, 30, $boundary)->equalTo($boundary))->toBeTrue()
        ->and($slots->next(7, 30, $boundary)->equalTo($boundary->addSeconds(30)))->toBeTrue()
        ->and($slots->latest(7, 30, $boundary->subMicrosecond())->equalTo($boundary->subSeconds(30)))->toBeTrue();
});

it('skips missed periods without drift when reference time advances', function (): void {
    $slots = new MonitorSlots();
    $initial = $slots->next(9, 30, CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $late = $initial->addSeconds(307);

    expect($slots->latest(9, 30, $late)->equalTo($initial->addSeconds(300)))->toBeTrue()
        ->and($slots->next(9, 30, $late)->equalTo($initial->addSeconds(330)))->toBeTrue();
});

it('rejects invalid persisted identities and intervals', function (int $id, int $interval): void {
    expect(fn () => (new MonitorSlots())->latest($id, $interval, CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class);
})->with([[0, 30], [-1, 30], [1, 0], [1, -30]]);

it('spreads a fixed synthetic monitor cohort over every second of the interval and preserves phases across timezones', function (int $interval): void {
    $slots = new MonitorSlots();
    $reference = CarbonImmutable::parse('2026-10-25T00:59:59.999999Z');
    $phases = [];
    $initialSlots = [];
    $shiftedSlots = [];
    $timezoneSlots = [];

    foreach (range(1, 6000) as $monitorId) {
        $initial = $slots->next($monitorId, $interval, $reference);
        $phase = $initial->getTimestamp() % $interval;
        $phases[$phase] = true;
        $later = $slots->next($monitorId, $interval, $reference->addSeconds($interval * 1000)->setTimezone('Europe/Rome'));
        $initialSlots[$monitorId] = $initial->getTimestamp();
        $shiftedSlots[$monitorId] = $later->getTimestamp() - $interval * 1000;
        $timezoneSlots[$monitorId] = $slots->next($monitorId, $interval, $reference->setTimezone('America/New_York'))->getTimestamp();
    }

    // This fixed-cohort regression checks deterministic phase coverage, not production throughput or a statistical SLA.
    expect(count($phases))->toBe($interval)
        ->and($shiftedSlots)->toBe($initialSlots)
        ->and($timezoneSlots)->toBe($initialSlots);
})->with([30, 60, 300, 47]);
