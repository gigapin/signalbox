<?php

declare(strict_types=1);

/** Remove old heartbeat receipts while keeping every answer that a slot still in validity can ask for. */

namespace App\Services\Metrics\Retention;

use App\Services\Metrics\MonitorAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A slot reads the latest receipt of the current generation at or before itself. Any slot at or after the cutoff therefore
 * finds the same answer as long as newer receipts stay and so does the latest older one of the current generation. Earlier
 * generations are never read again. No slot older than the cutoff can still run: work expires within one maximum interval.
 */
final class HeartbeatReceiptCleanup
{
    /** Discovery page size. */
    private const int CHUNK_SIZE = 200;

    /** @return int Receipts removed across all heartbeat monitors. */
    public function prune(): int
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays(MonitorAvailability::RAW_RETENTION_DAYS)->format('Y-m-d H:i:s.uP');
        $removed = 0;

        DB::table('monitor_heartbeats')
            ->select(['id', 'team_id', 'generation'])
            ->chunkById(self::CHUNK_SIZE, function ($states) use ($cutoff, &$removed): void {
                foreach ($states as $state) {
                    $removed += $this->pruneState((int) $state->team_id, (int) $state->id, (int) $state->generation, $cutoff);
                }
            });

        return $removed;
    }

    /**
     * @param int $teamId Owning team.
     * @param int $stateId Heartbeat state.
     * @param int $generation Current generation of the state.
     * @param string $cutoff Receipts before this instant may be removed.
     * @return int Receipts removed for the state.
     */
    private function pruneState(int $teamId, int $stateId, int $generation, string $cutoff): int
    {
        return DB::delete(<<<'SQL'
            DELETE FROM heartbeat_receipts
            WHERE team_id = :team_id
                AND monitor_heartbeat_id = :state_id
                AND received_at < :cutoff
                AND NOT (
                    generation = :generation
                    AND received_at = (
                        SELECT max(latest.received_at)
                        FROM heartbeat_receipts latest
                        WHERE latest.team_id = :latest_team_id
                            AND latest.monitor_heartbeat_id = :latest_state_id
                            AND latest.generation = :latest_generation
                            AND latest.received_at < :latest_cutoff
                    )
                )
            SQL, [
            'team_id' => $teamId,
            'state_id' => $stateId,
            'cutoff' => $cutoff,
            'generation' => $generation,
            'latest_team_id' => $teamId,
            'latest_state_id' => $stateId,
            'latest_generation' => $generation,
            'latest_cutoff' => $cutoff,
        ]);
    }
}
