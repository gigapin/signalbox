<?php

declare(strict_types=1);

/** Deliver the verification code that proves a channel destination is reachable and read. */

namespace App\Contracts\Notifications;

use App\Exceptions\ChannelDeliveryFailed;

/**
 * Called synchronously after the code digest is committed, outside any transaction, so a slow provider never
 * holds a lock. Implementations bound their own timeouts and re-run outbound protections for each attempt.
 *
 * The destination is unverified by definition, so the message carries only fixed text and the code: no
 * tenant-chosen text such as the channel label may reach a recipient who never asked for it.
 */
interface ChannelVerificationSender
{
    /**
     * @param ChannelConfiguration $configuration Destination and credentials.
     * @param string $code Six-digit plaintext code; never logged.
     * @throws ChannelDeliveryFailed When the provider refuses, times out or the recording-only stub cannot verify.
     */
    public function send(ChannelConfiguration $configuration, string $code): void;
}
