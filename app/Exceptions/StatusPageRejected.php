<?php

declare(strict_types=1);

/** Signal a status page that is malformed, references unusable monitors or exceeds the plan. */

namespace App\Exceptions;

use DomainException;

/** Raised by status page actions; transport layers translate it into a validation response on the `page` key. */
final class StatusPageRejected extends DomainException
{
}
