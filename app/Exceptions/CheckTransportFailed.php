<?php

declare(strict_types=1);

/** Represent recognized network failures without retaining private vendor exceptions. */

namespace App\Exceptions;

use App\Enums\Monitoring\CheckError;
use RuntimeException;

/** Raw transport messages and previous exceptions deliberately never cross this boundary. */
final class CheckTransportFailed extends RuntimeException
{
    /** @param CheckError $reason Stable public classification of the observed failure. */
    public function __construct(public readonly CheckError $reason)
    {
        parent::__construct($reason->message());
    }
}
