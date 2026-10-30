<?php

declare(strict_types=1);

/** Persist hourly rollups and derive daily rollups from them, always replacing the whole bucket. */

namespace App\Services\Metrics\Rollups;

use App\Data\Metrics\HourlyRollup;
use App\Data\Metrics\LatencyDistribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Bulk writes bypass TeamScope, so each statement names the owning team explicitly. */
final class RollupStore
{
    /** Percentile stored as the median. */
    private const int MEDIAN = 50;

    /** Percentile stored as the high latency indicator. */
    private const int HIGH = 95;

    /** Columns replaced when a bucket is written again. */
    private const array METRIC_COLUMNS = [
        'checks',
        'failures',
        'unknown_checks',
        'up_us',
        'down_us',
        'unknown_us',
        'excluded_us',
        'latency_samples',
        'latency_sum_ms',
        'latency_counts',
        'p50_ms',
        'p95_ms',
    ];

    /**
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor being rolled up.
     * @param list<HourlyRollup> $rollups Calculated hours.
     */
    public function writeHours(int $teamId, int $monitorId, array $rollups): void
    {
        $rows = [];

        foreach ($rollups as $rollup) {
            $rows[] = [
                'team_id' => $teamId,
                'monitor_id' => $monitorId,
                'hour' => CarbonImmutable::createFromTimestampUTC(intdiv($rollup->hour, 1_000_000))->format('Y-m-d\TH:i:s\Z'),
                'checks' => $rollup->checks,
                'failures' => $rollup->failures,
                'unknown_checks' => $rollup->unknownChecks,
                'up_us' => $rollup->durations->up,
                'down_us' => $rollup->durations->down,
                'unknown_us' => $rollup->durations->unknown,
                'excluded_us' => $rollup->durations->excluded,
                ...$this->latencyColumns($rollup->latency),
            ];
        }

        DB::table('check_rollups_hourly')->upsert($rows, ['monitor_id', 'hour'], self::METRIC_COLUMNS);
    }

    /**
     * Frequencies are merged and durations summed; hourly percentiles and means are never combined.
     *
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor being rolled up.
     * @param CarbonImmutable $day Start of a UTC day whose hours are all closed.
     */
    public function rebuildDay(int $teamId, int $monitorId, CarbonImmutable $day): void
    {
        $hours = DB::table('check_rollups_hourly')
            ->where('team_id', $teamId)
            ->where('monitor_id', $monitorId)
            ->where('hour', '>=', $day->format('Y-m-d\TH:i:s\Z'))
            ->where('hour', '<', $day->addDay()->format('Y-m-d\TH:i:s\Z'))
            ->get();

        if ($hours->isEmpty()) {
            return;
        }

        $latency = new LatencyDistribution();

        foreach ($hours as $hour) {
            $latency = $latency->merge(new LatencyDistribution($this->decodedCounts((string) $hour->latency_counts)));
        }

        $row = ['team_id' => $teamId, 'monitor_id' => $monitorId, 'day' => $day->format('Y-m-d'), 'hours' => $hours->count()];

        foreach (['checks', 'failures', 'unknown_checks', 'up_us', 'down_us', 'unknown_us', 'excluded_us'] as $column) {
            $row[$column] = (int) $hours->sum($column);
        }

        DB::table('check_rollups_daily')->upsert(
            [[...$row, ...$this->latencyColumns($latency)]],
            ['monitor_id', 'day'],
            ['hours', ...self::METRIC_COLUMNS],
        );
    }

    /**
     * @param LatencyDistribution $latency Exact bucket distribution.
     * @return array{latency_samples: int, latency_sum_ms: int, latency_counts: string, p50_ms: int|null, p95_ms: int|null} Column values.
     */
    private function latencyColumns(LatencyDistribution $latency): array
    {
        return [
            'latency_samples' => $latency->samples(),
            'latency_sum_ms' => $latency->sumMilliseconds(),
            // The object cast keeps an empty or sequential map a JSON object rather than a list.
            'latency_counts' => json_encode((object) $latency->counts, JSON_THROW_ON_ERROR),
            'p50_ms' => $latency->percentile(self::MEDIAN),
            'p95_ms' => $latency->percentile(self::HIGH),
        ];
    }

    /**
     * @param string $json Stored frequency map.
     * @return array<int, int> Sample count per millisecond value.
     */
    private function decodedCounts(string $json): array
    {
        $counts = [];

        foreach ((array) json_decode($json, true, 2, JSON_THROW_ON_ERROR) as $milliseconds => $count) {
            $counts[(int) $milliseconds] = (int) $count;
        }

        return $counts;
    }
}
