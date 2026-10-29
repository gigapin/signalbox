<?php

declare(strict_types=1);

/** Publish scalar notification identities without leaking encrypted recipient content or configuration into a broker. */

namespace App\Contracts\Notifications;

use Carbon\CarbonImmutable;

/** Publication can be repeated safely: database attempt admission is the authority on provider execution. */
interface NotificationWorkPublisher
{
    /**
     * @param int $teamId Owning tenant of the committed outbox row.
     * @param int $deliveryId Committed notification log identity.
     * @param CarbonImmutable $availableAt Earliest execution time, including coalescing or backoff.
     */
    public function publish(int $teamId, int $deliveryId, CarbonImmutable $availableAt): void;
}
