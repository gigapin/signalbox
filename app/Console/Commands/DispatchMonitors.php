<?php

declare(strict_types=1);

/** Expose bounded scheduling orchestration without placing domain rules in the console command. */

namespace App\Console\Commands;

use App\Services\Monitoring\Scheduling\ReserveMonitorChecks as Operation;
use Illuminate\Console\Command;

/** Remain inert until the scheduling pipeline is explicitly enabled. */
final class DispatchMonitors extends Command
{
    /** @var string Stable operational entry point. */
    protected $signature = 'monitors:dispatch';

    /** @var string Purpose displayed by artisan list. */
    protected $description = 'Reserve due regional monitor executions';

    /**
     * @param Operation $operation Cohesive scheduling operation.
     * @return int Success after reporting work processed, including disabled no-op runs.
     */
    public function handle(Operation $operation): int
    {
        if (config('monitor-scheduling.enabled') !== true) {
            $this->line('Monitor scheduling is disabled.');

            return self::SUCCESS;
        }

        $this->info('Processed: '.$operation->run());

        return self::SUCCESS;
    }
}
