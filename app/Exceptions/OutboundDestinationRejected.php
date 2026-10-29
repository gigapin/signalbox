<?php

declare(strict_types=1);

/** Signal that the shared SSRF boundary refused a connection before any socket was opened. */

namespace App\Exceptions;

use App\Enums\Security\OutboundRejection;
use RuntimeException;

/** The message is derived only from the reason, so reporting it cannot disclose private destinations. */
final class OutboundDestinationRejected extends RuntimeException
{
    /** @param OutboundRejection $reason Stable classification for results and diagnostics. */
    public function __construct(public readonly OutboundRejection $reason)
    {
        parent::__construct($reason->message());
    }
}
