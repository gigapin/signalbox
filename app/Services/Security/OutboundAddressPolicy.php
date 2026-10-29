<?php

declare(strict_types=1);

/** Decide whether one literal IP address may receive outbound monitor or webhook traffic. */

namespace App\Services\Security;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/** Compare packed address bytes so alternative textual spellings cannot bypass a range. */
final readonly class OutboundAddressPolicy
{
    /** @var list<string> IPv4 ranges that are private, shared, loopback, link-local, documentation, benchmarking, multicast or reserved. */
    private const array BLOCKED_IPV4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var string IPv6 global unicast space; everything outside it, NAT64 included, is denied. */
    private const string IPV6_GLOBAL_UNICAST = '2000::/3';

    /** @var list<string> Non-public ranges inside global unicast: IETF protocol assignments with Teredo, and documentation. */
    private const array BLOCKED_IPV6 = [
        '2001::/23',
        '2001:db8::/32',
        '3fff::/20',
    ];

    /** @var string 6to4 prefix whose next 32 bits embed an IPv4 address that must also be allowed. */
    private const string IPV6_6TO4 = '2002::/16';

    /** @var string IPv4-mapped prefix, evaluated exactly as the embedded IPv4 address. */
    private const string IPV4_MAPPED = '::ffff:0:0/96';

    /** @var list<string> Operator-approved exceptions, validated when the policy is built. */
    private array $allowed;

    /**
     * @param Repository $config Application configuration containing outbound-security.allowed_cidrs.
     * @throws LogicException When the exception list is not a list of valid CIDR strings.
     */
    public function __construct(Repository $config)
    {
        $allowed = $config->get('outbound-security.allowed_cidrs', []);

        if (!is_array($allowed) || !array_is_list($allowed)) {
            throw new LogicException('Outbound CIDR exceptions must be a list.');
        }

        foreach ($allowed as $cidr) {
            if (!is_string($cidr) || self::parseCidr($cidr) === null) {
                throw new LogicException('Outbound CIDR exceptions must be valid CIDR ranges.');
            }
        }

        $this->allowed = $allowed;
    }

    /**
     * @param string $address Literal IPv4 or IPv6 text; hostnames and legacy numeric forms are refused.
     * @return bool True only when the address is public or explicitly allowed by configuration.
     */
    public function allows(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (self::matchesAny($packed, $this->allowed)) {
            return true;
        }

        if (strlen($packed) === 4) {
            return !self::matchesAny($packed, self::BLOCKED_IPV4);
        }

        // Mapped addresses connect to the embedded IPv4 host, including its configured exceptions.
        if (self::matches($packed, self::IPV4_MAPPED)) {
            return $this->allows((string) inet_ntop(substr($packed, 12, 4)));
        }

        if (!self::matches($packed, self::IPV6_GLOBAL_UNICAST) || self::matchesAny($packed, self::BLOCKED_IPV6)) {
            return false;
        }

        if (self::matches($packed, self::IPV6_6TO4)) {
            return !self::matchesAny(substr($packed, 2, 4), self::BLOCKED_IPV4);
        }

        return true;
    }

    /**
     * @param string $packed Binary address from inet_pton().
     * @param list<string> $ranges CIDR ranges of either family.
     * @return bool Whether any same-family range contains the address.
     */
    private static function matchesAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $packed Binary address from inet_pton().
     * @param string $range Valid CIDR range.
     * @return bool Whether the prefix bits are equal; different families never match.
     */
    private static function matches(string $packed, string $range): bool
    {
        $parsed = self::parseCidr($range);

        if ($parsed === null || strlen($parsed[0]) !== strlen($packed)) {
            return false;
        }

        [$network, $bits] = $parsed;
        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $network, $bytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * @param string $cidr Address followed by a decimal prefix length, or a bare address meaning a host route.
     * @return array{string, int}|null Packed network and prefix length, or null when the text is invalid.
     */
    private static function parseCidr(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        $packed = @inet_pton($parts[0]);

        if ($packed === false) {
            return null;
        }

        $maximum = strlen($packed) * 8;

        if (!isset($parts[1])) {
            return [$packed, $maximum];
        }

        if (preg_match('/^[0-9]{1,3}$/D', $parts[1]) !== 1 || (int) $parts[1] > $maximum) {
            return null;
        }

        return [$packed, (int) $parts[1]];
    }
}
