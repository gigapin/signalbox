<?php

declare(strict_types=1);

/** Carry one authorized, fenced provider attempt across the database-to-network boundary. */

namespace App\Data\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;

/** Contains no Eloquent model and must never be serialized into a queue, response or diagnostic record. */
final readonly class NotificationAttempt
{
    /**
     * @param int $teamId Owning tenant.
     * @param int $deliveryId Persisted notification log identity.
     * @param string $token Ownership fence supplied by this queue envelope.
     * @param ChannelConfiguration $configuration Current verified destination, authorized immediately before acquisition.
     * @param ChannelMessage $message Persisted, stable recipient content and UUID.
     */
    public function __construct(
        public int $teamId,
        public int $deliveryId,
        public string $token,
        #[\SensitiveParameter] public ChannelConfiguration $configuration,
        #[\SensitiveParameter] public ChannelMessage $message,
    ) {
    }
}
