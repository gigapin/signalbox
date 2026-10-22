<?php

declare(strict_types=1);

/** Describe a destination that passed the outbound guard together with the addresses it may use. */

namespace App\Data\Security;

/** Transports must connect only to these pinned addresses; resolving the host again reopens DNS rebinding. */
final readonly class AuthorizedDestination
{
    /**
     * @param string|null $scheme Lowercase http or https for URLs; null for raw TLS or DNS endpoints.
     * @param string $host Normalized hostname or canonical IP literal, without IPv6 brackets.
     * @param int $port Explicit or scheme-default TCP/UDP port from 1 through 65535.
     * @param list<string> $addresses Nonempty canonical addresses, each allowed by the address policy.
     * @param string|null $url Normalized complete URL including private path and query; null for endpoints.
     */
    public function __construct(
        public ?string $scheme,
        public string $host,
        public int $port,
        public array $addresses,
        #[\SensitiveParameter]
        public ?string $url = null,
    ) {
    }

    /** @return bool Whether the host is itself an address, so no name pinning is needed. */
    public function isIpLiteral(): bool
    {
        return filter_var($this->host, FILTER_VALIDATE_IP) !== false;
    }

    /** @return string Scheme, host and port identity used to detect cross-origin redirects. */
    public function origin(): string
    {
        return ($this->scheme ?? 'tcp').'://'.strtolower($this->host).':'.$this->port;
    }

    /** @return array<string, mixed> Debug representation without the private URL. */
    public function __debugInfo(): array
    {
        return [
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'addresses' => $this->addresses,
            'url' => $this->url === null ? null : '[REDACTED]',
        ];
    }
}
