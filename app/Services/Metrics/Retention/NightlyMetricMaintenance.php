<?php

declare(strict_types=1);

/** Run the nightly metric steps in the order that keeps raw data until its rollups exist. */

namespace App\Services\Metrics\Retention;

use App\Data\Metrics\NightlyMaintenanceReport;
use App\Data\Metrics\PartitionDropOutcome;
use App\Data\Monitoring\SchedulingPass;
use App\Services\Metrics\Rollups\BuildCheckRollups;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every step is idempotent and independent: one failing is logged and the others still run, because removing a partition
 * depends only on its own verification, never on an earlier step having succeeded. Partition creation stays in its own
 * daily command, since the check engine depends on it and it must not wait for this longer run.
 */
final readonly class NightlyMetricMaintenance
{
    /**
     * @param BuildCheckRollups $rollups Rollup progress.
     * @param RollupRetention $rollupRetention Plan history cleanup.
     * @param HeartbeatReceiptCleanup $receipts Heartbeat history cleanup.
     * @param ExpiredResultPartitions $partitions Verified month removal.
     * @param ExecutionLedgerCleanup $ledger Ledger cleanup after removal.
     */
    public function __construct(
        private BuildCheckRollups $rollups,
        private RollupRetention $rollupRetention,
        private HeartbeatReceiptCleanup $receipts,
        private ExpiredResultPartitions $partitions,
        private ExecutionLedgerCleanup $ledger,
    ) {
    }

    /** @return NightlyMaintenanceReport What each step did. */
    public function run(): NightlyMaintenanceReport
    {
        $failed = [];
        $pass = $this->step('rollups', $failed, fn () => $this->rollups->runPass(), new SchedulingPass(0, 0));
        $rollupRows = $this->step('rollup_retention', $failed, fn () => $this->rollupRetention->prune(), 0);
        $receipts = $this->step('heartbeat_receipts', $failed, fn () => $this->receipts->prune(), 0);
        $partitions = $this->step('partitions', $failed, fn () => $this->partitions->drop(), new PartitionDropOutcome([], []));
        $executions = $this->step('execution_ledger', $failed, fn () => $this->ledger->prune(), 0);

        return new NightlyMaintenanceReport($pass->processed, $pass->failed, $rollupRows, $receipts, $partitions, $executions, $failed);
    }

    /**
     * @template T
     *
     * @param string $name Step identity for logs and the report.
     * @param list<string> $failed Failed step names, extended on failure.
     * @param callable(): T $step Work of the step.
     * @param T $fallback Result reported when the step fails.
     * @return T Result of the step.
     */
    private function step(string $name, array &$failed, callable $step, mixed $fallback): mixed
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            $failed[] = $name;

            Log::error('Nightly metric maintenance step failed; continuing with the next one.', ['step' => $name, 'exception' => $exception::class]);

            return $fallback;
        }
    }
}
