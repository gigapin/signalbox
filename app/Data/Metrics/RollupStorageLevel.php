<?php

declare(strict_types=1);

/** Describe the measured storage cost of one rollup level, without any tenant or monitor identity. */

namespace App\Data\Metrics;

/** Row and frequency sizes are logical stored bytes, compression included; only tableBytes counts indexes and page overhead. */
final readonly class RollupStorageLevel
{
    /**
     * @param string $level Rollup level, hourly or daily.
     * @param int $rows Stored buckets.
     * @param float $averageDistinctValues Mean number of distinct millisecond values per bucket.
     * @param int $maximumDistinctValues Largest number of distinct millisecond values in one bucket.
     * @param float $averageFrequencyBytes Mean stored size of the frequency map.
     * @param int $maximumFrequencyBytes Largest stored size of a frequency map.
     * @param float $averageRowBytes Mean stored size of a whole row.
     * @param int $tableBytes Current on-disk size of the table, including indexes and TOAST.
     */
    public function __construct(
        public string $level,
        public int $rows,
        public float $averageDistinctValues,
        public int $maximumDistinctValues,
        public float $averageFrequencyBytes,
        public int $maximumFrequencyBytes,
        public float $averageRowBytes,
        public int $tableBytes,
    ) {
    }
}
