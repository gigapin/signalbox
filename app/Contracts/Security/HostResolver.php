<?php

declare(strict_types=1);

/** Resolve hostnames for the outbound guard through a replaceable boundary. */

namespace App\Contracts\Security;

/** Implementations perform lookups only; the guard owns every authorization decision. */
interface HostResolver
{
    /**
     * @param string $host Normalized ASCII hostname without a terminal dot; never an IP literal.
     * @return list<string> IPv4 and IPv6 address texts, or an empty list when the lookup fails.
     */
    public function resolve(string $host): array;
}
