<?php

declare(strict_types=1);

/** Refuse verification deliveries until real channel adapters are bound. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Contracts\Notifications\ChannelVerificationSender;
use App\Exceptions\ChannelDeliveryFailed;

/**
 * Default binding while no adapter exists: managers see an explicit refusal rather than a code that never
 * arrives. Replaced by the adapter registry that delivers email, Slack, Telegram, webhook and the SMS stub.
 */
final class UnavailableChannelVerificationSender implements ChannelVerificationSender
{
    /**
     * @param string $channelName Unused label.
     * @param ChannelConfiguration $configuration Destination whose type is reported.
     * @param string $code Unused code.
     * @throws ChannelDeliveryFailed Always.
     */
    public function send(string $channelName, ChannelConfiguration $configuration, string $code): void
    {
        throw new ChannelDeliveryFailed('Delivery to '.$configuration->type()->label().' channels is not available yet.');
    }
}
