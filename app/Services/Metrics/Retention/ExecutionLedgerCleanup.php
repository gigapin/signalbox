<?php

declare(strict_types=1);

/** Remove ledger rows of months whose raw results no longer exist, which would otherwise grow without limit. */

namespace App\Services\Metrics\Retention;

use App\Services\Monitoring\Results\ResultPartitions;
use Illuminate\Support\Facades\DB;

/** Only terminal rows older than the oldest partition: they hold no result, and open work is still owned by recovery. */
final readonly class ExecutionLedgerCleanup
{
    /** Discovery page size. */
    private const int CHUNK_SIZE = 200;

    /** Rows removed per statement, so no single transaction stays long on a table the check engine uses constantly. */
    private const int BATCH_SIZE = 5000;

    /** @param ResultPartitions $partitions Oldest month with raw results. */
    public function __construct(private ResultPartitions $partitions)
    {
    }

    /** @return int Execution rows removed across all monitors; zero while no result partition exists. */
    public function prune(): int
    {
        $oldest = $this->partitions->oldestMonth();

        if ($oldest === null) {
            return 0;
        }

        $before = $oldest->format('Y-m-d\TH:i:s\Z');
        $removed = 0;

        DB::table('monitors')
            ->select(['id', 'team_id'])
            ->chunkById(self::CHUNK_SIZE, function ($monitors) use ($before, &$removed): void {
                foreach ($monitors as $monitor) {
                    $removed += $this->pruneMonitor((int) $monitor->team_id, (int) $monitor->id, $before);
                }
            });

        return $removed;
    }

    /**
     * @param int $teamId Owning team.
     * @param int $monitorId Monitor whose ledger is cleaned.
     * @param string $before Rows with an earlier slot are removed.
     * @return int Rows removed for the monitor.
     */
    private function pruneMonitor(int $teamId, int $monitorId, string $before): int
    {
        $removed = 0;

        do {
            $batch = DB::delete(<<<'SQL'
                DELETE FROM check_executions
                WHERE id IN (
                    SELECT id
                    FROM check_executions
                    WHERE team_id = :team_id
                        AND monitor_id = :monitor_id
                        AND slot_at < :before
                        AND state NOT IN ('pending', 'running')
                    LIMIT :limit
                )
                SQL, ['team_id' => $teamId, 'monitor_id' => $monitorId, 'before' => $before, 'limit' => self::BATCH_SIZE]);
            $removed += $batch;
        } while ($batch === self::BATCH_SIZE);

        return $removed;
    }
}
