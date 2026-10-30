<?php

declare(strict_types=1);

/** Split a monitored period into up, down, unknown and excluded time. */

namespace App\Data\Metrics;

/** All durations are whole microseconds, so summing buckets never accumulates rounding errors. */
final readonly class AvailabilityDurations
{
    /**
     * @param int $up Time an agreed up verdict held, after exclusions.
     * @param int $down Time an agreed down verdict held, after exclusions.
     * @param int $unknown Eligible time without a valid verdict.
     * @param int $excluded Monitored time inside pauses, plan suspensions or maintenance windows.
     */
    public function __construct(
        public int $up,
        public int $down,
        public int $unknown,
        public int $excluded,
    ) {
    }
}
