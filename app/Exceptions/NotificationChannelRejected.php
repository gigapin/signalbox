<?php

declare(strict_types=1);

/** Signal a notification channel change that its current state or the team limits forbid. */

namespace App\Exceptions;

use DomainException;

/** Raised by channel actions; transport layers translate it into a validation response on the `channel` key. */
final class NotificationChannelRejected extends DomainException
{
}
