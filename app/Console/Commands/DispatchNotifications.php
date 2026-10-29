<?php

declare(strict_types=1);

/** Recover pending incident deliveries after missed publications, provider failures or expired worker leases. */

namespace App\Console\Commands;

use App\Services\Notifications\PublishNotificationDeliveries;
use Illuminate\Console\Command;

/** This command only submits scalar jobs; it never sends provider requests inside the scheduler process. */
final class DispatchNotifications extends Command
{
    /** @var string Stable operational entrypoint for the delivery outbox. */
    protected $signature = 'notifications:dispatch';

    /** @var string Purpose displayed by Artisan help. */
    protected $description = 'Publish due incident notification deliveries and recover unfinished attempts';

    /**
     * @param PublishNotificationDeliveries $deliveries Bounded, individually isolated outbox publication pass.
     * @return int Nonzero on any publication failure, after allowing other tenants to proceed.
     */
    public function handle(PublishNotificationDeliveries $deliveries): int
    {
        $result = $deliveries->run();
        $this->info('Published '.$result['published'].' notification deliveries; '.$result['failed'].' publication failures.');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
