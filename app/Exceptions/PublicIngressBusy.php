<?php

declare(strict_types=1);

/** Signal bounded public-ingress lock contention without retaining SQL, credentials or recipient data. */

namespace App\Exceptions;

use RuntimeException;

/** The HTTP boundary translates expected transient contention into a generic retryable response. */
final class PublicIngressBusy extends RuntimeException
{
    /** Create an input-independent refusal without chaining the original database exception. */
    public function __construct()
    {
        parent::__construct('The service is busy. Please try again shortly.');
    }
}
