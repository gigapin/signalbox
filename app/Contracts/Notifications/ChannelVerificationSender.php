<?php

declare(strict_types=1);

/** Deliver the verification code that proves a channel destination is reachable and read. */

namespace App\Contracts\Notifications;

use App\Exceptions\ChannelDeliveryFailed;

/**
 * Called synchronously after the code digest is committed, outside any transaction, so a slow provider never
 * holds a lock. Implementations bound their own timeouts and re-run outbound protections for each attempt.
 */
interface ChannelVerificationSender
{
    /**
     * @param string $channelName Label the recipient sees, so the message is recognizable.
     * @param ChannelConfiguration $configuration Destination and credentials.
     * @param string $code Six-digit plaintext code; never logged.
     * @throws ChannelDeliveryFailed When the provider refuses, times out or the recording-only stub cannot verify.
     */
    public function send(string $channelName, ChannelConfiguration $configuration, string $code): void;
}
