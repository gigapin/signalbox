<?php

declare(strict_types=1);

/** Verify joint slot verdicts for one and two regions and the progress towards confirmation. */

use App\Data\Incidents\RegionalSequence;
use App\Enums\Incidents\SlotVerdictKind;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Services\Incidents\ConfirmationRule;
use Carbon\CarbonImmutable;

/**
 * @param MonitorRegion $region Sequence region.
 * @param MonitorStatus $status Outcome of the evaluated slot.
 * @param int $failures Consecutive failures ending at that slot.
 * @param CheckError|null $error Latest failure cause.
 * @param int $startedMinutesAgo First failing slot before the evaluated slot, in minutes.
 * @param CarbonImmutable|null $slot Processed slot; defaults to the evaluated slot.
 * @return RegionalSequence Sequence of revision one.
 */
function ruleSequence(MonitorRegion $region, MonitorStatus $status, int $failures = 0, ?CheckError $error = null, int $startedMinutesAgo = 0, ?CarbonImmutable $slot = null): RegionalSequence
{
    $slot ??= ruleSlot();

    return new RegionalSequence($region, 1, $slot, $status, $failures, $failures > 0 ? $slot->subMinutes($startedMinutesAgo) : null, $failures > 0 ? $error ?? CheckError::Timeout : null);
}

/** @return CarbonImmutable Evaluated UTC slot. */
function ruleSlot(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-28 10:10:00', 'UTC');
}

it('confirms a single region only once the threshold is reached', function (int $failures, SlotVerdictKind $expected): void {
    $sequences = ['eu' => ruleSequence(MonitorRegion::Eu, MonitorStatus::Down, $failures)];

    expect((new ConfirmationRule())->verdict([MonitorRegion::Eu], $sequences, 3, 1, ruleSlot())->kind)->toBe($expected);
})->with([
    'first failure' => [1, SlotVerdictKind::Inconclusive],
    'second failure' => [2, SlotVerdictKind::Inconclusive],
    'threshold' => [3, SlotVerdictKind::Confirmed],
    'beyond threshold' => [4, SlotVerdictKind::Confirmed],
]);

it('recovers a single region on its success and stays inconclusive without an observation', function (MonitorStatus $status, SlotVerdictKind $expected): void {
    $sequences = ['eu' => ruleSequence(MonitorRegion::Eu, $status)];

    expect((new ConfirmationRule())->verdict([MonitorRegion::Eu], $sequences, 3, 1, ruleSlot())->kind)->toBe($expected);
})->with([
    'up' => [MonitorStatus::Up, SlotVerdictKind::Recovered],
    'unknown' => [MonitorStatus::Unknown, SlotVerdictKind::Inconclusive],
]);

it('requires both regions in the same slot', function (array $eu, array $us, SlotVerdictKind $expected): void {
    $sequences = [
        'eu' => ruleSequence(MonitorRegion::Eu, ...$eu),
        'us' => ruleSequence(MonitorRegion::Us, ...$us),
    ];

    expect((new ConfirmationRule())->verdict([MonitorRegion::Eu, MonitorRegion::Us], $sequences, 3, 1, ruleSlot())->kind)->toBe($expected);
})->with([
    'both at threshold' => [[MonitorStatus::Down, 3], [MonitorStatus::Down, 3], SlotVerdictKind::Confirmed],
    'one below threshold' => [[MonitorStatus::Down, 5], [MonitorStatus::Down, 2], SlotVerdictKind::Inconclusive],
    'discordant' => [[MonitorStatus::Down, 5], [MonitorStatus::Up], SlotVerdictKind::Inconclusive],
    'one missing observation' => [[MonitorStatus::Down, 5], [MonitorStatus::Unknown], SlotVerdictKind::Inconclusive],
    'both up' => [[MonitorStatus::Up], [MonitorStatus::Up], SlotVerdictKind::Recovered],
    'one up one unknown' => [[MonitorStatus::Up], [MonitorStatus::Unknown], SlotVerdictKind::Inconclusive],
]);

it('waits until every configured region processed the slot of the current revision', function (string $case): void {
    $sequences = ['eu' => ruleSequence(MonitorRegion::Eu, MonitorStatus::Up)];

    $sequences['us'] = match ($case) {
        'earlier slot' => ruleSequence(MonitorRegion::Us, MonitorStatus::Up, slot: ruleSlot()->subMinute()),
        'stale revision' => new RegionalSequence(MonitorRegion::Us, 0, ruleSlot(), MonitorStatus::Up, 0, null, null),
        default => null,
    };

    expect((new ConfirmationRule())->verdict([MonitorRegion::Eu, MonitorRegion::Us], array_filter($sequences), 3, 1, ruleSlot())->kind)
        ->toBe(SlotVerdictKind::Inconclusive);
})->with(['missing region', 'earlier slot', 'stale revision']);

it('confirms with the earliest start and the most severe cause, earlier regions winning ties', function (CheckError $eu, CheckError $us, CheckError $expected): void {
    $sequences = [
        'eu' => ruleSequence(MonitorRegion::Eu, MonitorStatus::Down, 3, $eu, 2),
        'us' => ruleSequence(MonitorRegion::Us, MonitorStatus::Down, 4, $us, 3),
    ];
    $verdict = (new ConfirmationRule())->verdict([MonitorRegion::Eu, MonitorRegion::Us], $sequences, 3, 1, ruleSlot());

    expect($verdict->cause)->toBe($expected)
        ->and($verdict->startedAt?->equalTo(ruleSlot()->subMinutes(3)))->toBeTrue();
})->with([
    'more severe second region' => [CheckError::UnexpectedStatus, CheckError::Connection, CheckError::Connection],
    'tie keeps the first region' => [CheckError::Timeout, CheckError::Connection, CheckError::Timeout],
]);

it('reports the smallest current regional count as joint progress', function (): void {
    $rule = new ConfirmationRule();
    $both = [MonitorRegion::Eu, MonitorRegion::Us];
    $sequences = [
        'eu' => ruleSequence(MonitorRegion::Eu, MonitorStatus::Down, 4),
        'us' => ruleSequence(MonitorRegion::Us, MonitorStatus::Down, 2),
    ];

    expect($rule->progress([MonitorRegion::Eu], $sequences, 1))->toBe(4)
        ->and($rule->progress($both, $sequences, 1))->toBe(2)
        ->and($rule->progress($both, ['eu' => $sequences['eu']], 1))->toBe(0)
        ->and($rule->progress($both, $sequences, 2))->toBe(0);
});
