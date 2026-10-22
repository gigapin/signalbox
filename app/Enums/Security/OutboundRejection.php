<?php

declare(strict_types=1);

/** Classify refused outbound destinations without carrying the destination itself. */

namespace App\Enums\Security;

/** Stable reasons safe to persist in check results, logs and validation feedback. */
enum OutboundRejection: string
{
    case InvalidDestination = 'invalid_destination';
    case LocalName = 'local_name';
    case BlockedAddress = 'blocked_address';
    case ResolutionFailed = 'resolution_failed';
    case RedirectLimit = 'redirect_limit';
    case RedirectDowngrade = 'redirect_downgrade';

    /** @return string Generic English explanation that never includes hosts, addresses or URLs. */
    public function message(): string
    {
        return match ($this) {
            self::InvalidDestination => 'The destination is not a valid outbound HTTP, TLS or DNS target.',
            self::LocalName => 'Local hostnames are not allowed as outbound destinations.',
            self::BlockedAddress => 'The destination resolves to a private, reserved or otherwise blocked address.',
            self::ResolutionFailed => 'The destination hostname did not resolve to any address.',
            self::RedirectLimit => 'The redirect limit was exceeded.',
            self::RedirectDowngrade => 'Redirects from HTTPS to HTTP are not allowed.',
        };
    }
}
