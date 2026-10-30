<?php

declare(strict_types=1);

/** Calculate the hourly rollups of a closed UTC range from slot records and excluded time. */

namespace App\Services\Metrics\Rollups;

use App\Data\Metrics\HourlyRollup;
use App\Data\Metrics\LatencyDistribution;
use App\Data\Metrics\SlotRecord;
use App\Services\Metrics\AvailabilityTimeline;
use InvalidArgumentException;

/** Pure calculation: the same inputs always produce the same rollups, which makes rebuilding idempotent. */
final class HourlyRollupCalculator
{
    /** One hour in microseconds. */
    public const int HOUR = 3_600_000_000;

    /**
     * @param list<SlotRecord> $records Slots of the range plus earlier slots whose verdict may still hold at its start.
     * @param int $start Inclusive hour-aligned range start, in UTC microseconds.
     * @param int $end Exclusive hour-aligned range end.
     * @param int $monitoredFrom Instant the monitor came into existence; earlier time is not part of any duration.
     * @param list<array{start: int, end: int}> $exclusions Disjoint excluded intervals.
     * @return list<HourlyRollup> One rollup per monitored hour, including hours without any check.
     * @throws InvalidArgumentException When the range is not aligned to whole hours.
     */
    public function calculate(array $records, int $start, int $end, int $monitoredFrom, array $exclusions): array
    {
        if ($start % self::HOUR !== 0 || $end % self::HOUR !== 0) {
            throw new InvalidArgumentException('Rollup ranges must be aligned to whole UTC hours.');
        }

        $timeline = new AvailabilityTimeline(array_values(array_filter(array_map(
            static fn (SlotRecord $record) => $record->verdict(),
            $records,
        ))));
        $slotsByHour = $this->slotsByHour($records, $start);
        $rollups = [];

        for ($hour = $start; $hour < $end; $hour += self::HOUR) {
            $hourEnd = $hour + self::HOUR;

            if ($monitoredFrom >= $hourEnd) {
                continue;
            }

            $rollups[] = $this->rollup(
                $hour,
                $slotsByHour[$hour] ?? [],
                $timeline,
                max($hour, $monitoredFrom),
                $hourEnd,
                $exclusions,
            );
        }

        return $rollups;
    }

    /**
     * @param list<SlotRecord> $records Available slot records.
     * @param int $start Range start; earlier slots were counted by their own hour.
     * @return array<int, list<SlotRecord>> Slots of the range grouped by the hour of their slot.
     */
    private function slotsByHour(array $records, int $start): array
    {
        $grouped = [];

        foreach ($records as $record) {
            if ($record->slotAt >= $start) {
                $grouped[$record->slotAt - $record->slotAt % self::HOUR][] = $record;
            }
        }

        return $grouped;
    }

    /**
     * @param int $hour Hour start.
     * @param list<SlotRecord> $slots Slots scheduled in the hour.
     * @param AvailabilityTimeline $timeline Monitor availability over the whole range.
     * @param int $from Monitored start inside the hour.
     * @param int $to Hour end.
     * @param list<array{start: int, end: int}> $exclusions Disjoint excluded intervals.
     * @return HourlyRollup Calculated hour.
     */
    private function rollup(int $hour, array $slots, AvailabilityTimeline $timeline, int $from, int $to, array $exclusions): HourlyRollup
    {
        $checks = 0;
        $failures = 0;
        $unknownChecks = 0;
        $latencies = [];

        foreach ($slots as $slot) {
            $checks += $slot->executions;
            $failures += $slot->failures();
            $unknownChecks += $slot->unknownChecks();
            array_push($latencies, ...$slot->latencies());
        }

        return new HourlyRollup(
            $hour,
            $checks,
            $failures,
            $unknownChecks,
            $timeline->durations($from, $to, $exclusions),
            LatencyDistribution::fromSamples($latencies),
        );
    }
}
