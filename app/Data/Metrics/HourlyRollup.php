<?php

declare(strict_types=1);

/** Carry the calculated content of one UTC hour before it is persisted. */

namespace App\Data\Metrics;

/** Counts and latency belong to the hour of their slot; durations belong to the hour in which the time passed. */
final readonly class HourlyRollup
{
    /**
     * @param int $hour Hour start in UTC microseconds since the epoch.
     * @param int $checks Regional executions reserved for slots in this hour.
     * @param int $failures Regional observations that found the target down.
     * @param int $unknownChecks Regional executions without an observation.
     * @param AvailabilityDurations $durations Time split of the monitored part of the hour.
     * @param LatencyDistribution $latency Exact measured latency frequencies.
     */
    public function __construct(
        public int $hour,
        public int $checks,
        public int $failures,
        public int $unknownChecks,
        public AvailabilityDurations $durations,
        public LatencyDistribution $latency,
    ) {
    }
}
