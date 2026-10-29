<?php

declare(strict_types=1);

/** Describe a completed provider attempt without keeping any untrusted response or request secrets. */

namespace App\Data\Notifications;

use App\Enums\Notifications\DeliveryStatus;

/** Failure throws ChannelDeliveryFailed; SMS suppression must never be recorded as a sent message. */
final readonly class ChannelDeliveryReceipt
{
    /** @param bool $stubbed Whether this is the explicit no-delivery SMS stub. */
    public function __construct(public bool $stubbed = false)
    {
    }

    /** @return DeliveryStatus Terminal status for the future delivery log writer. */
    public function status(): DeliveryStatus
    {
        return $this->stubbed ? DeliveryStatus::Suppressed : DeliveryStatus::Sent;
    }

    /** @return string Fixed diagnostic text; provider bodies and credentials are never retained. */
    public function summary(): string
    {
        return $this->stubbed ? 'SMS stub: recorded without sending.' : 'Accepted by the provider.';
    }
}
