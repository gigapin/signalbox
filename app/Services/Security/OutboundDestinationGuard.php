<?php

declare(strict_types=1);

/** Authorize outbound HTTP, TLS and DNS destinations at access time, immediately before a connection. */

namespace App\Services\Security;

use App\Contracts\Security\HostResolver;
use App\Data\Security\AuthorizedDestination;
use App\Enums\Security\OutboundRejection;
use App\Exceptions\OutboundDestinationRejected;
use App\Services\Monitoring\MonitorInputSyntax;
use InvalidArgumentException;

/**
 * Shared SSRF boundary for checkers (M3) and webhooks (M5). Every resolved address must be allowed;
 * a single blocked answer refuses the whole destination, because the transport could otherwise pick it.
 */
final readonly class OutboundDestinationGuard
{
    /**
     * @param OutboundAddressPolicy $policy Literal address decision.
     * @param HostResolver $resolver Replaceable A/AAAA lookup.
     * @param MonitorInputSyntax $syntax Network-free URL and host normalization shared with form validation.
     */
    public function __construct(
        private OutboundAddressPolicy $policy,
        private HostResolver $resolver,
        private MonitorInputSyntax $syntax,
    ) {
    }

    /**
     * @param string $url Absolute HTTP(S) URL; userinfo and fragments are refused by normalization.
     * @return AuthorizedDestination Normalized URL with the addresses the transport must pin.
     * @throws OutboundDestinationRejected When syntax, local naming, resolution or any address is refused.
     */
    public function authorizeUrl(#[\SensitiveParameter] string $url): AuthorizedDestination
    {
        try {
            $normalized = $this->syntax->url($url);
        } catch (InvalidArgumentException) {
            throw new OutboundDestinationRejected(OutboundRejection::InvalidDestination);
        }

        $parts = parse_url($normalized);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new OutboundDestinationRejected(OutboundRejection::InvalidDestination);
        }

        $scheme = $parts['scheme'];
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return new AuthorizedDestination($scheme, $host, $port, $this->addresses($host), $normalized);
    }

    /**
     * @param string $host Hostname or IP literal of a raw TLS endpoint.
     * @param int $port TCP port from 1 through 65535.
     * @return AuthorizedDestination Endpoint with pinned addresses and no URL.
     * @throws OutboundDestinationRejected When the endpoint is invalid or not allowed.
     */
    public function authorizeEndpoint(string $host, int $port): AuthorizedDestination
    {
        if ($port < 1 || $port > 65535) {
            throw new OutboundDestinationRejected(OutboundRejection::InvalidDestination);
        }

        try {
            $normalized = $this->syntax->host($host);
        } catch (InvalidArgumentException) {
            throw new OutboundDestinationRejected(OutboundRejection::InvalidDestination);
        }

        return new AuthorizedDestination(null, $normalized, $port, $this->addresses($normalized));
    }

    /**
     * @param string $address Literal custom DNS resolver address; resolver hostnames are not supported.
     * @return AuthorizedDestination Resolver endpoint on port 53.
     * @throws OutboundDestinationRejected When the address is invalid or blocked.
     */
    public function authorizeResolver(string $address): AuthorizedDestination
    {
        try {
            $canonical = $this->syntax->ip($address);
        } catch (InvalidArgumentException) {
            throw new OutboundDestinationRejected(OutboundRejection::InvalidDestination);
        }

        return new AuthorizedDestination(null, $canonical, 53, $this->addresses($canonical));
    }

    /**
     * Apply the network-free part of the policy, suitable for form validation without any lookup.
     *
     * @param string $host Normalized hostname or canonical IP literal.
     * @throws OutboundDestinationRejected When the host is a local name or a blocked literal address.
     */
    public function assertLocallyAllowed(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->policy->allows($host)) {
                throw new OutboundDestinationRejected(OutboundRejection::BlockedAddress);
            }

            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new OutboundDestinationRejected(OutboundRejection::LocalName);
        }
    }

    /**
     * @param string $host Normalized hostname or canonical IP literal.
     * @return list<string> Distinct canonical addresses, all allowed.
     * @throws OutboundDestinationRejected When the host is local, unresolvable or any answer is blocked.
     */
    private function addresses(string $host): array
    {
        $this->assertLocallyAllowed($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach ($this->resolver->resolve($host) as $answer) {
            $packed = @inet_pton($answer);
            $canonical = $packed === false ? false : inet_ntop($packed);

            if ($canonical === false || !$this->policy->allows($canonical)) {
                throw new OutboundDestinationRejected(OutboundRejection::BlockedAddress);
            }

            $addresses[] = $canonical;
        }

        if ($addresses === []) {
            throw new OutboundDestinationRejected(OutboundRejection::ResolutionFailed);
        }

        return array_values(array_unique($addresses));
    }
}
