<?php

declare(strict_types=1);

/** Refuse verification when shared admission state cannot be trusted. */

namespace App\Exceptions;

use RuntimeException;

/** Backend diagnostics are deliberately excluded from the public exception chain. */
final class StatusPageUnlockUnavailable extends RuntimeException
{
    /** Create an input-independent retryable refusal. */
    public function __construct()
    {
        parent::__construct('Password verification is temporarily unavailable. Please try again shortly.');
    }
}
