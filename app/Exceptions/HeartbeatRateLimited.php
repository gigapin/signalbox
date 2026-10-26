<?php

declare(strict_types=1);

/** Report a credential traffic limit without exposing the credential or tenant identity. */

namespace App\Exceptions;

use RuntimeException;

/** HTTP adapters translate this recognized refusal to a generic 429 response. */
final class HeartbeatRateLimited extends RuntimeException
{
    /** @param int $retryAfterSeconds Positive remaining fixed-window duration in seconds. */
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many heartbeat requests.');
    }
}
