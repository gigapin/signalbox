<?php

declare(strict_types=1);

/** Signal a maintenance window change that would rewrite the past or is otherwise invalid. */

namespace App\Exceptions;

use DomainException;

/** Raised by maintenance actions; transport layers translate it into a validation response. */
final class MaintenanceWindowRejected extends DomainException
{
}
