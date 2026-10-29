<?php

declare(strict_types=1);

/** Turn per-region failure sequences into a joint verdict for one slot, without persistence. */

namespace App\Services\Incidents;

use App\Data\Incidents\RegionalSequence;
use App\Data\Incidents\SlotVerdict;
use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\SlotVerdictKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;

/**
 * With one region its own outcome decides; with two regions both must fail in the same slot after reaching the
 * threshold to confirm, and both must succeed in the same slot to recover. Anything else is inconclusive.
 */
final class ConfirmationRule
{
    /**
     * @param list<MonitorRegion> $regions Configured regions in canonical order.
     * @param array<string, RegionalSequence> $sequences Stored sequences keyed by region value.
     * @param int $threshold Positive number of consecutive failures each region must reach.
     * @param int $revision Current monitor execution revision.
     * @param CarbonImmutable $slot Slot being evaluated.
     * @return SlotVerdict Joint verdict; inconclusive until every region processed the slot.
     */
    public function verdict(array $regions, array $sequences, int $threshold, int $revision, CarbonImmutable $slot): SlotVerdict
    {
        $current = [];

        foreach ($regions as $region) {
            $sequence = $sequences[$region->value] ?? null;

            if ($sequence === null || !$sequence->covers($revision, $slot)) {
                return SlotVerdict::inconclusive();
            }

            $current[] = $sequence;
        }

        if ($this->all($current, static fn (RegionalSequence $sequence): bool => $sequence->lastStatus === MonitorStatus::Up)) {
            return new SlotVerdict(SlotVerdictKind::Recovered);
        }

        $confirmed = $this->all($current, static fn (RegionalSequence $sequence): bool => $sequence->lastStatus === MonitorStatus::Down
            && $sequence->consecutiveFailures >= $threshold);

        if (!$confirmed) {
            return SlotVerdict::inconclusive();
        }

        return $this->confirmation($current);
    }

    /**
     * @param list<MonitorRegion> $regions Configured regions.
     * @param array<string, RegionalSequence> $sequences Stored sequences keyed by region value.
     * @param int $revision Current monitor execution revision.
     * @return int Smallest current count among configured regions: the joint progress towards confirmation.
     */
    public function progress(array $regions, array $sequences, int $revision): int
    {
        $counts = [];

        foreach ($regions as $region) {
            $sequence = $sequences[$region->value] ?? null;
            $counts[] = $sequence !== null && $sequence->revision === $revision ? $sequence->consecutiveFailures : 0;
        }

        return $counts === [] ? 0 : min($counts);
    }

    /**
     * @param list<RegionalSequence> $confirming Failing sequences in canonical region order.
     * @return SlotVerdict Confirmation with the earliest start and the first most severe cause.
     */
    private function confirmation(array $confirming): SlotVerdict
    {
        $startedAt = null;
        $cause = null;

        foreach ($confirming as $sequence) {
            if ($sequence->startedAt !== null && ($startedAt === null || $sequence->startedAt->isBefore($startedAt))) {
                $startedAt = $sequence->startedAt;
            }

            // Earlier regions win ties, so the selected cause is deterministic.
            if ($sequence->lastError !== null
                && ($cause === null || IncidentSeverity::forCause($sequence->lastError)->rank() > IncidentSeverity::forCause($cause)->rank())) {
                $cause = $sequence->lastError;
            }
        }

        return new SlotVerdict(SlotVerdictKind::Confirmed, $startedAt, $cause);
    }

    /**
     * @param list<RegionalSequence> $sequences Candidate sequences.
     * @param \Closure(RegionalSequence): bool $predicate Condition each sequence must satisfy.
     * @return bool Whether every sequence satisfies the predicate.
     */
    private function all(array $sequences, \Closure $predicate): bool
    {
        foreach ($sequences as $sequence) {
            if (!$predicate($sequence)) {
                return false;
            }
        }

        return true;
    }
}
