<?php

declare(strict_types=1);

/** Submit committed scalar notification envelopes to their dedicated Redis queue, even with a synchronous default. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationWorkPublisher;
use App\Jobs\DeliverIncidentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Support\Str;

/** Each publication receives a unique ownership fence; the recipient message identity remains in the database. */
final readonly class RedisNotificationWorkPublisher implements NotificationWorkPublisher
{
    /** @param Factory $queues Queue connection resolver, explicitly selecting Redis. */
    public function __construct(private Factory $queues)
    {
    }

    /**
     * @param int $teamId Committed tenant boundary.
     * @param int $deliveryId Committed notification log identity.
     * @param CarbonImmutable $availableAt Earliest provider attempt time.
     */
    public function publish(int $teamId, int $deliveryId, CarbonImmutable $availableAt): void
    {
        $queue = $this->queues->connection('redis');
        $job = new DeliverIncidentNotification($teamId, $deliveryId, (string) Str::uuid());

        if ($availableAt->isAfter(CarbonImmutable::now('UTC'))) {
            $queue->later($availableAt, $job, '', 'notifications');
        } else {
            $queue->push($job, '', 'notifications');
        }
    }
}
