<?php

declare(strict_types=1);

/** Expose nightly metric maintenance to the scheduler and to operators. */

namespace App\Console\Commands;

use App\Services\Metrics\Retention\NightlyMetricMaintenance;
use Illuminate\Console\Command;

/** A failing status makes a kept partition or a failed step visible to whatever watches scheduled commands. */
final class RunNightlyMetricMaintenance extends Command
{
    /** @var string Stable operational entry point. */
    protected $signature = 'metrics:nightly';

    /** @var string Purpose displayed by artisan list. */
    protected $description = 'Advance rollups, apply retention and drop verified expired result partitions';

    /**
     * @param NightlyMetricMaintenance $maintenance Ordered maintenance steps.
     * @return int Success only when every step completed and no expired month had to be kept.
     */
    public function handle(NightlyMetricMaintenance $maintenance): int
    {
        $report = $maintenance->run();

        $this->info("Rollup hours written: {$report->rollupHours}; monitors failed: {$report->rollupMonitorsFailed}");
        $this->info("Rollup rows removed: {$report->rollupRowsRemoved}");
        $this->info("Heartbeat receipts removed: {$report->receiptsRemoved}");
        $this->info('Partitions dropped: '.($report->partitions->dropped === [] ? 'none' : implode(', ', $report->partitions->dropped)));
        $this->info("Execution rows removed: {$report->executionsRemoved}");

        foreach ($report->partitions->blocked as $month => $problems) {
            $this->error("Partition {$month} kept: ".json_encode($problems));
        }

        foreach ($report->failedSteps as $step) {
            $this->error("Step failed: {$step}");
        }

        return $report->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
