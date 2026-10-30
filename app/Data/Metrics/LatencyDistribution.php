<?php

declare(strict_types=1);

/** Hold an exact frequency map of whole-millisecond latencies, mergeable across buckets without averaging. */

namespace App\Data\Metrics;

use InvalidArgumentException;

/** Percentiles follow ADR-008: the value at position ceil(p * n) of the sorted samples; no samples yield null. */
final readonly class LatencyDistribution
{
    /** @var array<int, int> Positive sample count per millisecond value, in ascending value order. */
    public array $counts;

    /**
     * @param array<int, int> $counts Sample count per millisecond value; zero counts are discarded.
     * @throws InvalidArgumentException When a value or count is negative.
     */
    public function __construct(array $counts = [])
    {
        foreach ($counts as $milliseconds => $count) {
            if ($milliseconds < 0 || $count < 0) {
                throw new InvalidArgumentException('Latency frequencies cannot be negative.');
            }
        }

        $counts = array_filter($counts, static fn (int $count): bool => $count > 0);
        ksort($counts);

        $this->counts = $counts;
    }

    /**
     * @param list<int> $samples Measured whole milliseconds; unmeasured observations are never passed here.
     * @return self Distribution of those samples.
     */
    public static function fromSamples(array $samples): self
    {
        return new self(array_count_values($samples));
    }

    /**
     * @param self $other Distribution of a disjoint set of samples.
     * @return self Exact union of both sample sets.
     */
    public function merge(self $other): self
    {
        $counts = $this->counts;

        foreach ($other->counts as $milliseconds => $count) {
            $counts[$milliseconds] = ($counts[$milliseconds] ?? 0) + $count;
        }

        return new self($counts);
    }

    /** @return int Number of measured samples. */
    public function samples(): int
    {
        return array_sum($this->counts);
    }

    /** @return int Sum of all samples in milliseconds, the only valid input to a merged mean. */
    public function sumMilliseconds(): int
    {
        $sum = 0;

        foreach ($this->counts as $milliseconds => $count) {
            $sum += $milliseconds * $count;
        }

        return $sum;
    }

    /**
     * Integer arithmetic avoids the binary rounding of 0.95 * n, which could select the following position.
     *
     * @param int $percent Whole percentile from 1 to 100.
     * @return int|null Sample at position ceil(percent * n / 100); null without samples.
     * @throws InvalidArgumentException When the percentile is outside the range.
     */
    public function percentile(int $percent): ?int
    {
        if ($percent < 1 || $percent > 100) {
            throw new InvalidArgumentException('Percentiles range from 1 to 100.');
        }

        $position = intdiv($percent * $this->samples() + 99, 100);
        $seen = 0;

        foreach ($this->counts as $milliseconds => $count) {
            $seen += $count;

            if ($seen >= $position) {
                return $milliseconds;
            }
        }

        return null;
    }
}
