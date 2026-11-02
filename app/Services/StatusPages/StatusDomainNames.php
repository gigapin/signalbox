<?php

declare(strict_types=1);

/** Canonicalize customer hostnames and distinguish the application's own origin from custom page origins. */

namespace App\Services\StatusPages;

use App\Services\Monitoring\MonitorInputSyntax;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Domain input is a bare public DNS name, never a URL, IP literal, wildcard or platform subdomain. */
final readonly class StatusDomainNames
{
    /** @param MonitorInputSyntax $syntax Existing IDNA/label normalization, without network I/O. */
    public function __construct(private MonitorInputSyntax $syntax)
    {
    }

    /**
     * @param string $value Submitted bare hostname.
     * @return string Lowercase ASCII DNS name with room for the proof label.
     * @throws InvalidArgumentException When not a public customer hostname.
     */
    public function normalize(string $value): string
    {
        $name = $this->syntax->host($value, allowIp: false);
        $primary = $this->primary();
        $suffix = substr($name, (int) strrpos($name, '.') + 1);

        if (!str_contains($name, '.') || strlen($name) > 242 || preg_match('/^[0-9.]+$/D', $name) === 1
            || in_array($suffix, ['localhost', 'local', 'internal', 'test', 'invalid', 'example', 'onion'], true)
            || $name === $primary || str_ends_with($name, '.'.$primary)) {
            throw new InvalidArgumentException('Use a public DNS hostname outside the application domain.');
        }

        return $name;
    }

    /** @return string Configured primary hostname, independent of request headers. */
    public function primary(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * @param string $host Strict HTTP hostname without a port.
     * @return bool Whether this is the primary app origin or an explicit local/testing loopback origin.
     */
    public function isPrimary(string $host): bool
    {
        return $host === $this->primary() || (app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /**
     * @param mixed $peer Raw socket peer from REMOTE_ADDR, never a forwarded client address.
     * @return bool Whether the operator explicitly permits this peer to ask for certificate admission.
     */
    public function allowsTlsPeer(mixed $peer): bool
    {
        return is_string($peer) && IpUtils::checkIp($peer, (array) config('status-domains.tls_peers'));
    }
}
