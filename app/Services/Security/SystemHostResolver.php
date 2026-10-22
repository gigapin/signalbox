<?php

declare(strict_types=1);

/** Query the host's configured DNS resolvers for A and AAAA records. */

namespace App\Services\Security;

use App\Contracts\Security\HostResolver;

/**
 * Lookups use an absolute name, so resolver search domains and /etc/hosts aliases are never consulted.
 * dns_get_record() exposes no timeout: latency is bounded only by the system resolver configuration.
 */
final readonly class SystemHostResolver implements HostResolver
{
    /**
     * @param string $host Normalized ASCII hostname without a terminal dot.
     * @return list<string> Addresses from A and AAAA answers; CNAME targets are followed by the resolver.
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host.'.', DNS_A | DNS_AAAA);

        if (!is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = match ($record['type'] ?? null) {
                'A' => $record['ip'] ?? null,
                'AAAA' => $record['ipv6'] ?? null,
                default => null,
            };

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
