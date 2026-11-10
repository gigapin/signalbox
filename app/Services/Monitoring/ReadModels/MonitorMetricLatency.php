<?php

declare(strict_types=1);

/** Read exact regional latency statistics with bounded output and a maximum thirty-day raw range. */

namespace App\Services\Monitoring\ReadModels;

use App\Data\Metrics\TimeInterval;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Services\Metrics\MonitorAvailability;
use App\Services\Monitoring\Results\ResultPartitions;
use Carbon\CarbonImmutable;

/** PostgreSQL aggregates measured observations directly; percentiles are never averaged across buckets. */
final readonly class MonitorMetricLatency
{
    /** @param ResultPartitions $partitions Physical raw-data boundary, independent of the plan limit. */
    public function __construct(private ResultPartitions $partitions)
    {
    }

    /**
     * @param Monitor $monitor Authorized monitor; explicit tenant predicates also protect this query.
     * @param TimeInterval $window Validated range within the current plan's history.
     * @param string $region Allowlisted all/eu/us selection.
     * @param int $bucketSeconds One hour for 24h, one day for 7d/30d.
     * @return array<string, mixed> Available flag, raw provenance and nullable regional statistics for each half-open UTC bucket.
     */
    public function read(Monitor $monitor, TimeInterval $window, string $region, int $bucketSeconds): array
    {
        $now = CarbonImmutable::now('UTC');
        $start = $window->start->max($monitor->created_at ?? $window->start);
        $oldest = $this->partitions->oldestMonth();
        $available = !$start->isBefore($now->subDays(MonitorAvailability::RAW_RETENTION_DAYS))
            && $oldest !== null && !$start->isBefore($oldest);
        $series = [];
        $empty = ['meanMs' => null, 'p50Ms' => null, 'p95Ms' => null, 'samples' => 0];

        $summary = ['eu' => $empty, 'us' => $empty];

        for ($at = $window->start; $at->isBefore($window->end); $at = $at->addSeconds($bucketSeconds)) {
            $series[] = [
                'startsAt' => $at->toISOString(),
                'endsAt' => $at->addSeconds($bucketSeconds)->min($window->end)->toISOString(),
                'eu' => $empty,
                'us' => $empty,
            ];
        }
        if (!$available || !$window->end->isAfter($start)) {
            return ['available' => $available, 'source' => 'raw', 'summary' => $summary, 'series' => $series];
        }
        $rows = CheckResult::query()
            ->where('team_id', $monitor->team_id)->where('monitor_id', $monitor->id)
            ->where('slot_at', '>=', $start->toISOString())->where('slot_at', '<', $window->end->toISOString())
            ->where('recorded_at', '<=', $window->end->toISOString())
            ->where('checked_at', '<=', $window->end->toISOString())
            ->where('kind', 'observed')->whereNotNull('response_time_ms')
            ->when($region !== 'all', static fn ($query) => $query->where('region', $region))
            ->selectRaw('FLOOR(EXTRACT(EPOCH FROM (slot_at - ?::timestamptz)) / ?)::int AS bucket', [$window->start->toISOString(), $bucketSeconds])
            ->selectRaw('region, COUNT(*)::int AS samples, AVG(response_time_ms)::float8 AS mean_ms')
            ->selectRaw('percentile_disc(0.50) WITHIN GROUP (ORDER BY response_time_ms) AS p50_ms')
            ->selectRaw('percentile_disc(0.95) WITHIN GROUP (ORDER BY response_time_ms) AS p95_ms')
            ->groupByRaw('GROUPING SETS ((bucket, region), (region))')->get();

        foreach ($rows as $row) {
            $key = $row->getAttribute('region');
            if (!in_array($key, ['eu', 'us'], true)) {
                continue;
            }
            $statistics = [
                'meanMs' => round((float) $row->getAttribute('mean_ms'), 2),
                'p50Ms' => (int) $row->getAttribute('p50_ms'),
                'p95Ms' => (int) $row->getAttribute('p95_ms'),
                'samples' => (int) $row->getAttribute('samples'),
            ];
            if ($row->getAttribute('bucket') === null) {
                $summary[$key] = $statistics;
            } else {
                $bucket = (int) $row->getAttribute('bucket');
                if (isset($series[$bucket])) {
                    $series[$bucket][$key] = $statistics;
                }
            }
        }

        return ['available' => true, 'source' => 'raw', 'summary' => $summary, 'series' => $series];
    }
}
