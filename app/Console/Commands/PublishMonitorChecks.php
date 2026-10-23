<?php

declare(strict_types=1);

/** Expose bounded scheduling orchestration without placing domain rules in the console command. */

namespace App\Console\Commands;

use App\Services\Monitoring\Scheduling\PublishMonitorChecks as Operation;
use Illuminate\Console\Command;

/** Remain inert until the scheduling pipeline is explicitly enabled. */
final class PublishMonitorChecks extends Command
{
    /** @var string Stable operational entry point. */
    protected $signature = 'monitors:publish';

    /** @var string Purpose displayed by artisan list. */
    protected $description = 'Publish committed regional execution envelopes';

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
