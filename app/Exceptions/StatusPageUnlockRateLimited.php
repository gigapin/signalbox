<?php

declare(strict_types=1);

/** Describe a bounded refusal without exposing page details or submitted credentials. */

namespace App\Exceptions;

use RuntimeException;

/** The HTTP boundary returns 429 with the remaining global waiting time. */
final class StatusPageUnlockRateLimited extends RuntimeException
{
    /** @param int $retryAfter Positive waiting time in whole seconds. */
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many password attempts. Please try again later.');
    }
}
