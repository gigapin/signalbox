<?php

declare(strict_types=1);

/** Prove that the rollups of a result month are complete and match the raw data, before that raw data may be removed. */

namespace App\Services\Metrics\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only technical access across tenants, returning counts and never tenant data. Durations cannot be rebuilt cheaply
 * in SQL, so they are checked for adding up to each monitored hour, while counts and latency are compared with the raw rows.
 */
final class RollupVerification
{
    /**
     * @param CarbonImmutable $month Start of the UTC result month.
     * @param CarbonImmutable $closedThrough Instant up to which every monitor's rollups must have advanced, so that later hours no longer need this month for earlier verdicts.
     * @return array<string, int> Number of offending rows per problem; empty when the month is safe to remove.
     */
    public function problems(CarbonImmutable $month, CarbonImmutable $closedThrough): array
    {
        $from = $month->utc()->format('Y-m-d\TH:i:s\Z');
        $to = $month->utc()->addMonth()->format('Y-m-d\TH:i:s\Z');

        return array_filter([
            'open_work' => $this->openWork($from, $to),
            'rollups_behind' => $this->monitorsBehind($to, $closedThrough->utc()->format('Y-m-d\TH:i:s\Z')),
            'hours_missing_or_incomplete' => $this->incompleteHours($from, $to),
            'hours_differ_from_raw' => $this->hoursDifferingFromRaw($from, $to),
            'days_differ_from_hours' => $this->daysDifferingFromHours($from, $to),
        ]);
    }

    /**
     * Recovery of such work would write into this month, and even recreate it after a drop.
     *
     * @param string $from Inclusive month start.
     * @param string $to Exclusive month end.
     * @return int Executions of the month not yet terminal.
     */
    private function openWork(string $from, string $to): int
    {
        return DB::table('check_executions')
            ->where('slot_at', '>=', $from)
            ->where('slot_at', '<', $to)
            ->whereIn('state', ['pending', 'running'])
            ->count();
    }

    /**
     * @param string $to Exclusive month end.
     * @param string $closedThrough Required rollup progress.
     * @return int Monitors alive in the month whose rollups have not reached the required instant.
     */
    private function monitorsBehind(string $to, string $closedThrough): int
    {
        return (int) DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM monitors monitor
            LEFT JOIN check_rollup_cursors cursor_row
                ON cursor_row.team_id = monitor.team_id
                AND cursor_row.monitor_id = monitor.id
            WHERE monitor.created_at < :to
                AND (cursor_row.hourly_through IS NULL OR cursor_row.hourly_through < :closed_through)
            SQL, ['to' => $to, 'closed_through' => $closedThrough]);
    }

    /**
     * @param string $from Inclusive month start.
     * @param string $to Exclusive month end.
     * @return int Monitored hours of the month without a row, or whose durations do not add up to the monitored time.
     */
    private function incompleteHours(string $from, string $to): int
    {
        return (int) DB::scalar(<<<'SQL'
            WITH expected AS (
                SELECT monitor.team_id, monitor.id AS monitor_id, monitor.created_at, series.hour
                FROM monitors monitor
                CROSS JOIN LATERAL generate_series(
                    GREATEST(date_bin('1 hour', monitor.created_at, TIMESTAMPTZ '2000-01-01 00:00:00+00'), :from_hour::timestamptz),
                    :to_hour::timestamptz - INTERVAL '1 hour',
                    INTERVAL '1 hour'
                ) AS series(hour)
                WHERE monitor.created_at < :to_monitor
            )
            SELECT count(*)
            FROM expected
            LEFT JOIN check_rollups_hourly rollup
                ON rollup.monitor_id = expected.monitor_id
                AND rollup.team_id = expected.team_id
                AND rollup.hour = expected.hour
            WHERE rollup.monitor_id IS NULL
                OR rollup.up_us + rollup.down_us + rollup.unknown_us + rollup.excluded_us
                    <> (EXTRACT(EPOCH FROM expected.hour + INTERVAL '1 hour' - GREATEST(expected.hour, expected.created_at)) * 1000000)::bigint
            SQL, ['from_hour' => $from, 'to_hour' => $to, 'to_monitor' => $to]);
    }

    /**
     * @param string $from Inclusive month start.
     * @param string $to Exclusive month end.
     * @return int Monitor hours whose counts or latency totals differ from the ledger and raw results, in either direction.
     */
    private function hoursDifferingFromRaw(string $from, string $to): int
    {
        return (int) DB::scalar(<<<'SQL'
            WITH raw AS (
                SELECT
                    execution.team_id,
                    execution.monitor_id,
                    date_bin('1 hour', execution.slot_at, TIMESTAMPTZ '2000-01-01 00:00:00+00') AS hour,
                    count(*) AS checks,
                    count(*) FILTER (WHERE result.kind = 'observed' AND result.status = 'down') AS failures,
                    count(*) FILTER (WHERE result.kind IS DISTINCT FROM 'observed') AS unknown_checks,
                    count(result.response_time_ms) FILTER (WHERE result.kind = 'observed') AS samples,
                    COALESCE(sum(result.response_time_ms) FILTER (WHERE result.kind = 'observed'), 0) AS sum_ms
                FROM check_executions execution
                LEFT JOIN check_results result
                    ON result.team_id = execution.team_id
                    AND result.execution_id = execution.id
                    AND result.slot_at = execution.slot_at
                    AND result.slot_at >= :result_from
                    AND result.slot_at < :result_to
                WHERE execution.slot_at >= :slot_from
                    AND execution.slot_at < :slot_to
                GROUP BY 1, 2, 3
            ),
            stored AS (
                SELECT *
                FROM check_rollups_hourly
                WHERE hour >= :hour_from
                    AND hour < :hour_to
            )
            SELECT count(*)
            FROM stored
            FULL JOIN raw
                ON raw.team_id = stored.team_id
                AND raw.monitor_id = stored.monitor_id
                AND raw.hour = stored.hour
            WHERE stored.monitor_id IS NULL
                OR COALESCE(raw.checks, 0) <> stored.checks
                OR COALESCE(raw.failures, 0) <> stored.failures
                OR COALESCE(raw.unknown_checks, 0) <> stored.unknown_checks
                OR COALESCE(raw.samples, 0) <> stored.latency_samples
                OR COALESCE(raw.sum_ms, 0) <> stored.latency_sum_ms
            SQL, [
            'result_from' => $from,
            'result_to' => $to,
            'slot_from' => $from,
            'slot_to' => $to,
            'hour_from' => $from,
            'hour_to' => $to,
        ]);
    }

    /**
     * @param string $from Inclusive month start.
     * @param string $to Exclusive month end.
     * @return int Monitor days whose stored row is absent or differs from the sum of its hours, in either direction.
     */
    private function daysDifferingFromHours(string $from, string $to): int
    {
        return (int) DB::scalar(<<<'SQL'
            WITH hours AS (
                SELECT
                    team_id,
                    monitor_id,
                    (hour AT TIME ZONE 'UTC')::date AS day,
                    count(*) AS hours,
                    sum(checks) AS checks,
                    sum(failures) AS failures,
                    sum(unknown_checks) AS unknown_checks,
                    sum(up_us) AS up_us,
                    sum(down_us) AS down_us,
                    sum(unknown_us) AS unknown_us,
                    sum(excluded_us) AS excluded_us,
                    sum(latency_samples) AS latency_samples,
                    sum(latency_sum_ms) AS latency_sum_ms
                FROM check_rollups_hourly
                WHERE hour >= :hour_from
                    AND hour < :hour_to
                GROUP BY 1, 2, 3
            ),
            stored AS (
                SELECT *
                FROM check_rollups_daily
                WHERE day >= (:day_from::timestamptz AT TIME ZONE 'UTC')::date
                    AND day < (:day_to::timestamptz AT TIME ZONE 'UTC')::date
            )
            SELECT count(*)
            FROM stored
            FULL JOIN hours
                ON hours.team_id = stored.team_id
                AND hours.monitor_id = stored.monitor_id
                AND hours.day = stored.day
            WHERE stored.monitor_id IS NULL
                OR hours.monitor_id IS NULL
                OR hours.hours <> stored.hours
                OR hours.checks <> stored.checks
                OR hours.failures <> stored.failures
                OR hours.unknown_checks <> stored.unknown_checks
                OR hours.up_us <> stored.up_us
                OR hours.down_us <> stored.down_us
                OR hours.unknown_us <> stored.unknown_us
                OR hours.excluded_us <> stored.excluded_us
                OR hours.latency_samples <> stored.latency_samples
                OR hours.latency_sum_ms <> stored.latency_sum_ms
            SQL, ['hour_from' => $from, 'hour_to' => $to, 'day_from' => $from, 'day_to' => $to]);
    }
}
