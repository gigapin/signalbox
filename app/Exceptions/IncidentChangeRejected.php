<?php

declare(strict_types=1);

/** Signal a manual incident change that the lifecycle or timeline rules do not admit. */

namespace App\Exceptions;

use DomainException;

/** Raised by incident actions; transport layers translate it into a validation response. */
final class IncidentChangeRejected extends DomainException
{
}
