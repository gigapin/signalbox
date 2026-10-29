<?php

declare(strict_types=1);

/** Define one bounded provider attempt, shared by verification and incident deliveries. */

namespace App\Contracts\Notifications;

use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\ChannelMessage;
use App\Exceptions\ChannelDeliveryFailed;

/** Adapters do no persistence, retries or tenant lookup; callers authorize and supply an immutable snapshot. */
interface ChannelAdapter
{
    /**
     * @param ChannelConfiguration $configuration Validated destination and credentials for this family.
     * @param ChannelMessage $message Stable identity and recipient-only content; never logged.
     * @return ChannelDeliveryReceipt Provider acceptance or explicit stub suppression, without provider content.
     * @throws ChannelDeliveryFailed When the destination, connection or provider refuses the attempt.
     */
    public function send(#[\SensitiveParameter] ChannelConfiguration $configuration, #[\SensitiveParameter] ChannelMessage $message): ChannelDeliveryReceipt;
}
