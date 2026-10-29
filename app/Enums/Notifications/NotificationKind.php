<?php

declare(strict_types=1);

/** Enumerate the incident moments that produce a notification delivery. */

namespace App\Enums\Notifications;

/** Values match the `notification_logs.kind` check constraint. */
enum NotificationKind: string
{
    /** First step of the policy after an incident opens or reopens. */
    case Opened = 'opened';

    /** A later escalation step whose delay elapsed while the incident stayed open. */
    case Escalated = 'escalated';

    /** Resolution summary sent to the channels already notified. */
    case Resolved = 'resolved';
}
