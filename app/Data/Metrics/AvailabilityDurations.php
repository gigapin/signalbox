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

    /** @return int Time the monitor was expected to be watched: everything except exclusions. */
    public function eligible(): int
    {
        return $this->up + $this->down + $this->unknown;
    }

    /**
     * @param self $other Durations of a period disjoint from this one.
     * @return self Durations of both periods together.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->up + $other->up,
            $this->down + $other->down,
            $this->unknown + $other->unknown,
            $this->excluded + $other->excluded,
        );
    }
}
