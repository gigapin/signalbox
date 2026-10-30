<?php

declare(strict_types=1);

/** Measure what the exact latency frequencies actually cost, as ADR-008 requires of this representation. */

namespace App\Services\Metrics\Rollups;

use App\Data\Metrics\RollupStorageLevel;
use Illuminate\Support\Facades\DB;

/** Operator measurement across all tenants: it returns only aggregates and reads every row, so it is never run on a request path. */
final class RollupStorageReport
{
    /** @return list<RollupStorageLevel> Hourly and daily measurements, in that order. */
    public function measure(): array
    {
        return [
            $this->level('hourly', 'check_rollups_hourly'),
            $this->level('daily', 'check_rollups_daily'),
        ];
    }

    /**
     * @param string $level Rollup level reported to the operator.
     * @param string $table Internal constant table name, never request text.
     * @return RollupStorageLevel Aggregated measurement; averages are zero on an empty table.
     */
    private function level(string $level, string $table): RollupStorageLevel
    {
        $row = DB::selectOne(<<<SQL
            SELECT
                count(*) AS rows,
                coalesce(avg(distinct_values), 0) AS average_distinct,
                coalesce(max(distinct_values), 0) AS maximum_distinct,
                coalesce(avg(pg_column_size(bucket.latency_counts)), 0) AS average_frequency_bytes,
                coalesce(max(pg_column_size(bucket.latency_counts)), 0) AS maximum_frequency_bytes,
                coalesce(avg(pg_column_size(bucket.*)), 0) AS average_row_bytes,
                pg_total_relation_size('{$table}') AS table_bytes
            FROM {$table} bucket
            CROSS JOIN LATERAL (
                SELECT count(*) AS distinct_values FROM jsonb_object_keys(bucket.latency_counts)
            ) frequencies
            SQL);

        return new RollupStorageLevel(
            $level,
            (int) $row->rows,
            (float) $row->average_distinct,
            (int) $row->maximum_distinct,
            (float) $row->average_frequency_bytes,
            (int) $row->maximum_frequency_bytes,
            (float) $row->average_row_bytes,
            (int) $row->table_bytes,
        );
    }
}
