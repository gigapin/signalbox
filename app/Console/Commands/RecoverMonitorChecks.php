<?php

declare(strict_types=1);

/** Expose bounded scheduling orchestration without placing domain rules in the console command. */

namespace App\Console\Commands;

use App\Services\Monitoring\Scheduling\RecoverMonitorChecks as Operation;
use Illuminate\Console\Command;

/** Remain inert until the scheduling pipeline is explicitly enabled. */
final class RecoverMonitorChecks extends Command
{
    /** @var string Stable operational entry point. */
    protected $signature = 'monitors:recover';

    /** @var string Purpose displayed by artisan list. */
    protected $description = 'Recover expired or abandoned monitor executions';

    /**
     * @param Operation $operation Cohesive scheduling operation.
     * @return int Success after reporting work processed, including disabled no-op runs; failure when any item failed.
     */
    public function handle(Operation $operation): int
    {
        if (config('monitor-scheduling.enabled') !== true) {
            $this->line('Monitor scheduling is disabled.');

            return self::SUCCESS;
        }

        $pass = $operation->runPass();
        $this->info('Processed: '.$pass->processed);

        if ($pass->failed > 0) {
            // Item details are logged by identity; the scheduler sees a failed run while healthy items progressed.
            $this->error('Failed: '.$pass->failed);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
