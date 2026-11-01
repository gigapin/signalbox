<?php

declare(strict_types=1);

/** Report the outcome of one nightly metric maintenance run. */

namespace App\Data\Metrics;

/** Counts only: no tenant data. */
final readonly class NightlyMaintenanceReport
{
    /**
     * @param int $rollupHours Hourly rollups written.
     * @param int $rollupMonitorsFailed Monitors whose rollups failed and resume next run.
     * @param int $rollupRowsRemoved Rollups removed beyond plan history.
     * @param int $receiptsRemoved Heartbeat receipts removed.
     * @param PartitionDropOutcome $partitions Result months removed or kept.
     * @param int $executionsRemoved Ledger rows removed.
     * @param list<string> $failedSteps Steps that stopped with an error; later steps still ran.
     */
    public function __construct(
        public int $rollupHours,
        public int $rollupMonitorsFailed,
        public int $rollupRowsRemoved,
        public int $receiptsRemoved,
        public PartitionDropOutcome $partitions,
        public int $executionsRemoved,
        public array $failedSteps,
    ) {
    }

    /** @return bool Whether everything completed, with no delay an operator should look at. */
    public function succeeded(): bool
    {
        return $this->failedSteps === [] && $this->rollupMonitorsFailed === 0 && $this->partitions->blocked === [];
    }
}
