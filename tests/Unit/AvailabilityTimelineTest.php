<?php

declare(strict_types=1);

/** Verify the temporal rules of ADR-009 and regional agreement as pure calculations over microseconds. */

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\SlotRecord;
use App\Enums\Monitoring\MonitorStatus;
use App\Services\Metrics\AvailabilityTimeline;
use App\Services\Metrics\Rollups\HourlyRollupCalculator;

/**
 * @param int $seconds Seconds after the fixed test origin.
 * @return int Instant in microseconds.
 */
function timelineAt(int $seconds): int
{
    return ($seconds + 1_790_000_000 - 1_790_000_000 % 3600) * 1_000_000;
}

/**
 * @param int $slot Slot in seconds after the origin.
 * @param int $interval Cadence in seconds.
 * @param list<array{0: MonitorStatus, 1: int}> $observations Status and observation offset from the slot in seconds.
 * @param int $executions Regional executions reserved for the slot.
 * @return SlotRecord Slot fixture without latencies.
 */
function timelineSlot(int $slot, int $interval, array $observations, int $executions = 1): SlotRecord
{
    return new SlotRecord(
        timelineAt($slot),
        timelineAt($slot + $interval),
        $executions,
        array_map(static fn (array $observation): array => [
            'status' => $observation[0],
            'checkedAt' => timelineAt($slot + $observation[1]),
            'latencyMs' => null,
        ], $observations),
    );
}

/**
 * @param list<SlotRecord> $records Slots to evaluate.
 * @param int $start Period start in seconds after the origin.
 * @param int $end Period end in seconds after the origin.
 * @param list<array{0: int, 1: int}> $exclusions Disjoint excluded intervals in seconds after the origin.
 * @return AvailabilityDurations Calculated durations.
 */
function timelineDurations(array $records, int $start, int $end, array $exclusions = []): AvailabilityDurations
{
    $verdicts = array_values(array_filter(array_map(static fn (SlotRecord $record) => $record->verdict(), $records)));

    return (new AvailabilityTimeline($verdicts))->durations(
        timelineAt($start),
        timelineAt($end),
        array_map(static fn (array $interval): array => ['start' => timelineAt($interval[0]), 'end' => timelineAt($interval[1])], $exclusions),
    );
}

/**
 * @param AvailabilityDurations $durations Calculated durations.
 * @return list<int> Up, down, unknown and excluded time in whole seconds.
 */
function timelineSeconds(AvailabilityDurations $durations): array
{
    return [
        intdiv($durations->up, 1_000_000),
        intdiv($durations->down, 1_000_000),
        intdiv($durations->unknown, 1_000_000),
        intdiv($durations->excluded, 1_000_000),
    ];
}

it('treats a period without verdicts as entirely unknown', function (): void {
    expect(timelineSeconds(timelineDurations([], 0, 3600)))->toBe([0, 0, 3600, 0]);
});

it('counts time before the first observation as unknown and holds a sample until the next expected slot expires', function (): void {
    $records = [timelineSlot(0, 300, [[MonitorStatus::Up, 10]])];

    // Up from second 10 until the following slot (300) has itself expired (600).
    expect(timelineSeconds(timelineDurations($records, 0, 3600)))->toBe([590, 0, 3010, 0]);
});

it('ends a verdict at the next valid sample when it arrives before the expiry', function (): void {
    $records = [
        timelineSlot(0, 300, [[MonitorStatus::Up, 10]]),
        timelineSlot(300, 300, [[MonitorStatus::Down, 20]]),
    ];

    expect(timelineSeconds(timelineDurations($records, 0, 3600)))->toBe([310, 580, 2710, 0]);
});

it('keeps continuous coverage when samples arrive on time', function (): void {
    $records = array_map(static fn (int $slot): SlotRecord => timelineSlot($slot, 300, [[MonitorStatus::Up, 1]]), range(0, 3300, 300));

    // Only the first second before the first observation is unknown.
    expect(timelineSeconds(timelineDurations($records, 0, 3600)))->toBe([3599, 0, 1, 0]);
});

it('lets the previous verdict age out across a slot without any observation', function (): void {
    $records = [
        timelineSlot(0, 300, [[MonitorStatus::Up, 0]]),
        timelineSlot(300, 300, []),
        timelineSlot(600, 300, []),
        timelineSlot(900, 300, [[MonitorStatus::Up, 0]]),
    ];

    expect(timelineSeconds(timelineDurations($records, 0, 1200)))->toBe([600 + 300, 0, 300, 0]);
});

it('does not let a late result fill the gap before its observation or replace a newer slot', function (): void {
    $records = [
        timelineSlot(0, 300, [[MonitorStatus::Up, 0]]),
        // Slot 300 observed down at second 900, after slot 600 already reported up at second 600.
        timelineSlot(300, 300, [[MonitorStatus::Down, 600]]),
        timelineSlot(600, 300, [[MonitorStatus::Up, 0]]),
    ];

    expect(timelineSeconds(timelineDurations($records, 0, 1200)))->toBe([1200, 0, 0, 0]);
});

it('requires regional agreement for two reserved regions', function (MonitorStatus $eu, MonitorStatus $us, array $expected): void {
    $records = [timelineSlot(0, 300, [[$eu, 5], [$us, 10]], 2)];

    expect(timelineSeconds(timelineDurations($records, 0, 600)))->toBe($expected);
})->with([
    'both up from the later observation' => [MonitorStatus::Up, MonitorStatus::Up, [590, 0, 10, 0]],
    'both down from the later observation' => [MonitorStatus::Down, MonitorStatus::Down, [0, 590, 10, 0]],
    'discordant stays unknown' => [MonitorStatus::Up, MonitorStatus::Down, [0, 0, 600, 0]],
]);

it('treats incomplete regional coverage as unknown and ends the earlier verdict', function (): void {
    $records = [
        timelineSlot(0, 300, [[MonitorStatus::Up, 0], [MonitorStatus::Up, 0]], 2),
        timelineSlot(300, 300, [[MonitorStatus::Up, 0]], 2),
    ];

    expect(timelineSeconds(timelineDurations($records, 0, 900)))->toBe([300, 0, 600, 0]);
});

it('uses the reserved cadence of each slot, not a current interval', function (): void {
    $records = [
        timelineSlot(0, 60, [[MonitorStatus::Up, 0]]),
        timelineSlot(600, 300, [[MonitorStatus::Up, 0]]),
    ];

    // 120 seconds for the first slot, 600 for the second one.
    expect(timelineSeconds(timelineDurations($records, 0, 1500)))->toBe([720, 0, 780, 0]);
});

it('subtracts excluded time once and adds up to the whole period', function (): void {
    $records = [
        timelineSlot(0, 300, [[MonitorStatus::Up, 0]]),
        timelineSlot(300, 300, [[MonitorStatus::Down, 0]]),
    ];
    $durations = timelineDurations($records, 0, 1200, [[200, 400], [800, 1000]]);

    expect(timelineSeconds($durations))->toBe([200, 400, 200, 400])
        ->and($durations->up + $durations->down + $durations->unknown + $durations->excluded)->toBe(1200 * 1_000_000);
});

it('keeps microsecond precision instead of rounding durations', function (): void {
    $record = new SlotRecord(timelineAt(0), timelineAt(300), 1, [
        ['status' => MonitorStatus::Up, 'checkedAt' => timelineAt(0) + 1, 'latencyMs' => null],
    ]);
    $durations = (new AvailabilityTimeline([$record->verdict()]))->durations(timelineAt(0), timelineAt(3600), []);

    expect($durations->up)->toBe(600_000_000 - 1)
        ->and($durations->unknown)->toBe(3_000_000_000 + 1);
});

it('splits a verdict across hours and starts the first hour at monitor creation', function (): void {
    $records = [timelineSlot(3300, 300, [[MonitorStatus::Up, 0]])];
    $rollups = (new HourlyRollupCalculator())->calculate($records, timelineAt(0), timelineAt(7200), timelineAt(1800), []);

    expect($rollups)->toHaveCount(2)
        ->and(timelineSeconds($rollups[0]->durations))->toBe([300, 0, 1500, 0])
        ->and(timelineSeconds($rollups[1]->durations))->toBe([300, 0, 3300, 0])
        ->and($rollups[0]->checks)->toBe(1)
        ->and($rollups[1]->checks)->toBe(0);
});

it('uses an earlier slot for durations without counting it again in the range', function (): void {
    $records = [timelineSlot(-60, 300, [[MonitorStatus::Down, 0]])];
    $rollups = (new HourlyRollupCalculator())->calculate($records, timelineAt(0), timelineAt(3600), timelineAt(-3600), []);

    expect(timelineSeconds($rollups[0]->durations))->toBe([0, 540, 3060, 0])
        ->and($rollups[0]->checks)->toBe(0)
        ->and($rollups[0]->failures)->toBe(0);
});

it('skips hours before the monitor existed and rejects unaligned ranges', function (): void {
    $calculator = new HourlyRollupCalculator();

    expect($calculator->calculate([], timelineAt(0), timelineAt(7200), timelineAt(3600), []))->toHaveCount(1)
        ->and(fn () => $calculator->calculate([], timelineAt(1), timelineAt(3600), timelineAt(0), []))->toThrow(InvalidArgumentException::class);
});
