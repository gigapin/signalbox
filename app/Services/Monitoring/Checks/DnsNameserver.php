<?php

declare(strict_types=1);

/** Select trusted system infrastructure separately from user-selected DNS destinations. */

namespace App\Services\Monitoring\Checks;

use App\Services\Security\OutboundDestinationGuard;
use LogicException;
use React\Dns\Config\Config;

/** No public fallback, search suffix or hosts-file lookup is introduced. */
final class DnsNameserver
{
    /** @var string|null First valid system nameserver, loaded once per service lifetime. */
    private ?string $system = null;

    /**
     * @param string|null $custom Literal resolver from private monitor configuration.
     * @param OutboundDestinationGuard $guard Authorizer for user-selected resolver addresses.
     * @return string Literal nameserver endpoint on port 53.
     * @throws LogicException When the environment has no usable nameserver configuration.
     */
    public function select(?string $custom, OutboundDestinationGuard $guard): string
    {
        return $custom === null ? $this->system() : $this->endpoint($guard->authorizeResolver($custom)->host);
    }

    /**
     * @return string First literal system resolver, which may be an internal infrastructure address.
     * @throws LogicException When no valid system resolver is configured.
     */
    public function system(): string
    {
        if ($this->system !== null) {
            return $this->system;
        }

        foreach (Config::loadSystemConfigBlocking()->nameservers as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                return $this->system = $this->endpoint($address);
            }
        }

        throw new LogicException('The system DNS resolver is not configured.');
    }

    /**
     * @param string $address Validated IPv4 or IPv6 literal.
     * @return string Unambiguous nameserver endpoint with the standard DNS port.
     */
    private function endpoint(string $address): string
    {
        return (str_contains($address, ':') ? '['.$address.']' : $address).':53';
    }
}
