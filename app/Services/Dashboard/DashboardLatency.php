<?php

declare(strict_types=1);

/** Read bounded hourly regional latency measurements from the partitioned terminal-result ledger. */

namespace App\Services\Dashboard;

use App\Models\CheckResult;
use Carbon\CarbonImmutable;

/** Preserve missing latency as null and keep simulated regions separate instead of averaging their statuses. */
final readonly class DashboardLatency
{
    /** Number of one-hour buckets in the rolling dashboard window. */
    public const int HOURS = 24;

    /**
     * @param int $teamId Explicit tenant, additionally constrained by TeamScope.
     * @param int|null $monitorId Authorized selected monitor, or null for an empty dashboard.
     * @param CarbonImmutable $end Exclusive UTC end; future recordings and slots are excluded.
     * @return list<array{at: string, eu: array{meanMs: float|null, samples: int}, us: array{meanMs: float|null, samples: int}}> Hourly means of measured observations, including zero milliseconds and excluding null measurements.
     */
    public function series(int $teamId, ?int $monitorId, CarbonImmutable $end): array
    {
        $end = $end->utc();
        $start = $end->subHours(self::HOURS);
        $startsAt = $start->format('Y-m-d\TH:i:s.u\Z');
        $endsAt = $end->format('Y-m-d\TH:i:s.u\Z');
        $series = [];

        for ($hour = 0; $hour < self::HOURS; ++$hour) {
            $series[] = [
                'at' => $start->addHours($hour)->format('Y-m-d\TH:i:s.u\Z'),
                'eu' => ['meanMs' => null, 'samples' => 0],
                'us' => ['meanMs' => null, 'samples' => 0],
            ];
        }

        if ($monitorId === null) {
            return $series;
        }

        $rows = CheckResult::query()
            ->where('team_id', $teamId)
            ->where('monitor_id', $monitorId)
            // Strings retain microseconds that the default database DateTime binding format would discard.
            ->where('slot_at', '>=', $startsAt)
            ->where('slot_at', '<', $endsAt)
            ->where('recorded_at', '<=', $endsAt)
            ->where('kind', 'observed')
            ->whereNotNull('response_time_ms')
            ->selectRaw('FLOOR(EXTRACT(EPOCH FROM (slot_at - ?::timestamptz)) / 3600)::int AS bucket', [$startsAt])
            ->selectRaw('region, AVG(response_time_ms)::float8 AS mean_ms, COUNT(*)::int AS samples')
            ->groupByRaw('bucket, region')
            ->get();

        foreach ($rows as $row) {
            $bucket = (int) $row->getAttribute('bucket');
            $region = $row->getAttribute('region');

            if (isset($series[$bucket]) && in_array($region, ['eu', 'us'], true)) {
                $series[$bucket][$region] = [
                    'meanMs' => round((float) $row->getAttribute('mean_ms'), 2),
                    'samples' => (int) $row->getAttribute('samples'),
                ];
            }
        }

        return $series;
    }
}
