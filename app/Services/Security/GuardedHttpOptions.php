<?php

declare(strict_types=1);

/** Translate an authorized destination into Guzzle options that connect only to its pinned addresses. */

namespace App\Services\Security;

use App\Data\Security\AuthorizedDestination;

/**
 * Pinning through CURLOPT_RESOLVE closes the DNS rebinding window between authorization and connect.
 * Redirects stay disabled: every hop must pass RedirectPolicy and receive its own options.
 */
final readonly class GuardedHttpOptions
{
    /**
     * @param AuthorizedDestination $destination URL destination returned by OutboundDestinationGuard::authorizeUrl().
     * @return array<string, mixed> Options for PendingRequest::withOptions(); callers may add timeouts but must not override these keys.
     */
    public function for(AuthorizedDestination $destination): array
    {
        $curl = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Environment proxy variables would move the connection, and its DNS lookup, outside the pin.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];

        if (!$destination->isIpLiteral()) {
            $addresses = array_map(
                static fn (string $address): string => str_contains($address, ':') ? '['.$address.']' : $address,
                $destination->addresses,
            );

            $curl[CURLOPT_RESOLVE] = [$destination->host.':'.$destination->port.':'.implode(',', $addresses)];
        }

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => $curl,
        ];
    }
}
