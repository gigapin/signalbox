<?php

declare(strict_types=1);

/** Represent a half-open UTC interval [start, end) and merge sets of intervals for time-weighted metrics. */

namespace App\Data\Metrics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Empty intervals are not representable; clipping that leaves nothing returns null. */
final readonly class TimeInterval
{
    /** @var CarbonImmutable Inclusive UTC start. */
    public CarbonImmutable $start;

    /** @var CarbonImmutable Exclusive UTC end. */
    public CarbonImmutable $end;

    /**
     * @param CarbonImmutable $start Inclusive start, normalized to UTC.
     * @param CarbonImmutable $end Exclusive end, normalized to UTC.
     * @throws InvalidArgumentException When the end is not after the start.
     */
    public function __construct(CarbonImmutable $start, CarbonImmutable $end)
    {
        if (!$end->isAfter($start)) {
            throw new InvalidArgumentException('An interval must end after it starts.');
        }

        $this->start = $start->utc();
        $this->end = $end->utc();
    }

    /**
     * @param self $bounds Interval to clip to.
     * @return self|null Intersection with the bounds, or null when they do not overlap.
     */
    public function clip(self $bounds): ?self
    {
        $start = $this->start->max($bounds->start);
        $end = $this->end->min($bounds->end);

        return $end->isAfter($start) ? new self($start, $end) : null;
    }

    /** @return int Length in whole seconds, truncating fractions. */
    public function seconds(): int
    {
        return (int) $this->start->diffInSeconds($this->end);
    }

    /**
     * Overlapping and adjacent intervals are merged, so excluded time is never counted twice.
     *
     * @param list<self> $intervals Intervals in any order.
     * @return list<self> Disjoint, non-adjacent intervals ordered by start.
     */
    public static function union(array $intervals): array
    {
        usort($intervals, static fn (self $left, self $right): int => $left->start <=> $right->start);

        $merged = [];

        foreach ($intervals as $interval) {
            $last = array_key_last($merged);

            if ($last !== null && !$interval->start->isAfter($merged[$last]->end)) {
                $merged[$last] = new self($merged[$last]->start, $merged[$last]->end->max($interval->end));

                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }
}
