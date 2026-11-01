<?php

declare(strict_types=1);

/** Remove rollups beyond the history of each team's current plan (ADR-008, ADR-010). */

namespace App\Services\Metrics\Retention;

use App\Models\Team;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use App\Services\Monitoring\Results\ResultPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads apply the plan's limit at once; this is the physical cleanup that follows, and an upgrade recovers nothing removed.
 * Rollups of a month whose raw results still exist are kept, because they are the proof its removal is waiting for.
 */
final readonly class RollupRetention
{
    /** Discovery page size. */
    private const int CHUNK_SIZE = 200;

    /**
     * @param TeamEntitlement $entitlement Effective plan of each team.
     * @param PlanLimits $limits History allowed by that plan.
     * @param ResultPartitions $partitions Oldest month with raw results.
     */
    public function __construct(
        private TeamEntitlement $entitlement,
        private PlanLimits $limits,
        private ResultPartitions $partitions,
    ) {
    }

    /** @return int Hourly and daily rows removed across all teams. */
    public function prune(): int
    {
        $now = CarbonImmutable::now('UTC');
        $oldest = $this->partitions->oldestMonth();
        $removed = 0;

        Team::query()->chunkById(self::CHUNK_SIZE, function ($teams) use ($now, $oldest, &$removed): void {
            foreach ($teams as $team) {
                $limit = $now->subDays($this->limits->rollupRetentionDays($this->entitlement->effectivePlan($team, $now)));

                $removed += $this->pruneTeam($team->id, $oldest === null ? $limit : $limit->min($oldest));
            }
        });

        return $removed;
    }

    /**
     * Per monitor, because the rollup keys start with the monitor and each delete stays small and indexed.
     *
     * @param int $teamId Owning team.
     * @param CarbonImmutable $limit Rollups ending at or before this instant are removed.
     * @return int Rows removed for the team.
     */
    private function pruneTeam(int $teamId, CarbonImmutable $limit): int
    {
        $hour = $limit->startOfHour()->format('Y-m-d\TH:i:s\Z');
        $day = $limit->startOfDay()->format('Y-m-d');
        $removed = 0;

        foreach (DB::table('monitors')->where('team_id', $teamId)->pluck('id') as $monitorId) {
            $removed += DB::table('check_rollups_hourly')->where('team_id', $teamId)->where('monitor_id', $monitorId)->where('hour', '<', $hour)->delete();
            $removed += DB::table('check_rollups_daily')->where('team_id', $teamId)->where('monitor_id', $monitorId)->where('day', '<', $day)->delete();
        }

        return $removed;
    }
}
