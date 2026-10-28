<?php

declare(strict_types=1);

/** Verify how one region's consecutive failure sequence advances, resets and ignores stale slots. */

use App\Data\Incidents\RegionalSequence;
use App\Data\Monitoring\RecordedResult;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;

/**
 * @param int $slot Slot number, sixty seconds apart from a fixed UTC origin.
 * @param string $outcome One of up, down, missed, cancelled or infrastructure.
 * @param int $revision Monitor execution revision of the result.
 * @return RecordedResult Synthetic EU result.
 */
function sequenceResult(int $slot, string $outcome, int $revision = 1): RecordedResult
{
    $kind = match ($outcome) {
        'up', 'down' => CheckResultKind::Observed,
        'missed' => CheckResultKind::Missed,
        'cancelled' => CheckResultKind::Cancelled,
        default => CheckResultKind::Infrastructure,
    };
    $status = match ($outcome) {
        'up' => MonitorStatus::Up,
        'down' => MonitorStatus::Down,
        default => MonitorStatus::Unknown,
    };

    return new RecordedResult(1, 1, MonitorRegion::Eu, sequenceSlot($slot), $revision, $kind, $status, $outcome === 'down' ? CheckError::Timeout : null, sequenceSlot($slot)->addSeconds(5));
}

/**
 * @param int $slot Slot number.
 * @return CarbonImmutable UTC slot instant.
 */
function sequenceSlot(int $slot): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC')->addMinutes($slot);
}

/**
 * @param list<array{int, string}> $results Slot numbers and outcomes applied in order.
 * @return RegionalSequence Sequence after applying every result; stale results leave it unchanged.
 */
function applySequence(array $results): RegionalSequence
{
    $sequence = RegionalSequence::fresh(MonitorRegion::Eu, 1);

    foreach ($results as [$slot, $outcome]) {
        $sequence = $sequence->advance(sequenceResult($slot, $outcome), sequenceSlot($slot - 1)) ?? $sequence;
    }

    return $sequence;
}

it('counts consecutive observed failures from the first failing slot', function (): void {
    $sequence = applySequence([[0, 'up'], [1, 'down'], [2, 'down'], [3, 'down']]);

    expect($sequence->consecutiveFailures)->toBe(3)
        ->and($sequence->startedAt?->equalTo(sequenceSlot(1)))->toBeTrue()
        ->and($sequence->lastError)->toBe(CheckError::Timeout)
        ->and($sequence->lastStatus)->toBe(MonitorStatus::Down);
});

it('interrupts the sequence on success or any non-observation', function (string $interruption): void {
    $sequence = applySequence([[1, 'down'], [2, 'down'], [3, $interruption], [4, 'down']]);

    expect($sequence->consecutiveFailures)->toBe(1)
        ->and($sequence->startedAt?->equalTo(sequenceSlot(4)))->toBeTrue();
})->with(['up', 'missed', 'cancelled', 'infrastructure']);

it('records the interrupting outcome without failures', function (string $outcome, MonitorStatus $status): void {
    $sequence = applySequence([[1, 'down'], [2, $outcome]]);

    expect($sequence->consecutiveFailures)->toBe(0)
        ->and($sequence->startedAt)->toBeNull()
        ->and($sequence->lastError)->toBeNull()
        ->and($sequence->lastStatus)->toBe($status);
})->with([
    'success' => ['up', MonitorStatus::Up],
    'missed' => ['missed', MonitorStatus::Unknown],
    'infrastructure' => ['infrastructure', MonitorStatus::Unknown],
]);

it('restarts the count when a slot is skipped', function (): void {
    $sequence = applySequence([[1, 'down'], [2, 'down'], [4, 'down']]);

    expect($sequence->consecutiveFailures)->toBe(1)
        ->and($sequence->startedAt?->equalTo(sequenceSlot(4)))->toBeTrue();
});

it('ignores duplicate and out of order slots', function (int $slot): void {
    $sequence = applySequence([[1, 'down'], [2, 'down']]);

    expect($sequence->advance(sequenceResult($slot, 'up'), sequenceSlot($slot - 1)))->toBeNull();
})->with(['duplicate' => 2, 'earlier' => 1]);

it('starts over for a new monitor revision even at an earlier slot', function (): void {
    $sequence = applySequence([[5, 'down'], [6, 'down']]);
    $advanced = $sequence->advance(sequenceResult(3, 'down', 2), sequenceSlot(2));

    expect($advanced?->revision)->toBe(2)
        ->and($advanced?->consecutiveFailures)->toBe(1)
        ->and($advanced?->covers(2, sequenceSlot(3)))->toBeTrue()
        ->and($advanced?->covers(1, sequenceSlot(3)))->toBeFalse();
});
