<?php

declare(strict_types=1);

/** Enumerate the states of one logged notification delivery. */

namespace App\Enums\Notifications;

/** Values match the `notification_logs.status` check constraint; delivery jobs write them from M5.03. */
enum DeliveryStatus: string
{
    /** Recorded and waiting for, or retrying, its delivery. */
    case Pending = 'pending';

    /** Accepted by the provider; `sent_at` is set. */
    case Sent = 'sent';

    /** Given up after the delivery retries. */
    case Failed = 'failed';

    /** Deliberately not sent, for example during maintenance or quiet hours. */
    case Suppressed = 'suppressed';
}
