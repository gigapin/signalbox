<?php

declare(strict_types=1);

/** Turn slot verdicts into the up/down time they establish, following the temporal rules of ADR-009. */

namespace App\Services\Metrics;

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\SlotVerdict;
use App\Enums\Monitoring\MonitorStatus;

/** Pure calculation over UTC microseconds; everything outside an up/down segment is unknown. */
final readonly class AvailabilityTimeline
{
    /** @var list<array{start: int, end: int, status: MonitorStatus}> Disjoint up/down segments ordered by start. */
    private array $segments;

    /** @param list<SlotVerdict> $verdicts Slot verdicts in any order. */
    public function __construct(array $verdicts)
    {
        usort($verdicts, static fn (SlotVerdict $left, SlotVerdict $right): int => [$left->effectiveAt, $left->slotAt] <=> [$right->effectiveAt, $right->slotAt]);

        $this->segments = $this->segmentsOf($this->withoutLateVerdicts($verdicts));
    }

    /**
     * @param int $start Inclusive period start.
     * @param int $end Exclusive period end.
     * @param list<array{start: int, end: int}> $exclusions Disjoint excluded intervals.
     * @return AvailabilityDurations Durations of the period; they add up exactly to its length.
     */
    public function durations(int $start, int $end, array $exclusions): AvailabilityDurations
    {
        $excluded = 0;

        foreach ($exclusions as $exclusion) {
            $excluded += $this->overlap($start, $end, $exclusion['start'], $exclusion['end']);
        }

        $up = 0;
        $down = 0;

        foreach ($this->segments as $segment) {
            $time = $this->overlap($start, $end, $segment['start'], $segment['end']);

            foreach ($exclusions as $exclusion) {
                $time -= $this->overlap(max($start, $segment['start']), min($end, $segment['end']), $exclusion['start'], $exclusion['end']);
            }

            if ($segment['status'] === MonitorStatus::Up) {
                $up += $time;
            } else {
                $down += $time;
            }
        }

        return new AvailabilityDurations($up, $down, $end - $start - $excluded - $up - $down, $excluded);
    }

    /**
     * A result arriving after a newer slot is history: it cannot replace what the newer slot established.
     *
     * @param list<SlotVerdict> $verdicts Verdicts ordered by effective instant.
     * @return list<SlotVerdict> Verdicts with strictly increasing slots.
     */
    private function withoutLateVerdicts(array $verdicts): array
    {
        $applied = [];
        $latestSlot = null;

        foreach ($verdicts as $verdict) {
            if ($latestSlot !== null && $verdict->slotAt <= $latestSlot) {
                continue;
            }

            $latestSlot = $verdict->slotAt;
            $applied[] = $verdict;
        }

        return $applied;
    }

    /**
     * @param list<SlotVerdict> $verdicts Applied verdicts ordered by effective instant.
     * @return list<array{start: int, end: int, status: MonitorStatus}> Up/down segments ending at expiry or at the next verdict.
     */
    private function segmentsOf(array $verdicts): array
    {
        $segments = [];

        foreach ($verdicts as $index => $verdict) {
            $end = $verdict->validUntil;

            if (isset($verdicts[$index + 1])) {
                $end = min($end, $verdicts[$index + 1]->effectiveAt);
            }

            if ($verdict->status !== MonitorStatus::Unknown && $end > $verdict->effectiveAt) {
                $segments[] = ['start' => $verdict->effectiveAt, 'end' => $end, 'status' => $verdict->status];
            }
        }

        return $segments;
    }

    /**
     * @param int $firstStart Start of the first interval.
     * @param int $firstEnd End of the first interval.
     * @param int $secondStart Start of the second interval.
     * @param int $secondEnd End of the second interval.
     * @return int Length of their intersection, zero when disjoint.
     */
    private function overlap(int $firstStart, int $firstEnd, int $secondStart, int $secondEnd): int
    {
        return max(0, min($firstEnd, $secondEnd) - max($firstStart, $secondStart));
    }
}
