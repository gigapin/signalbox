<?php

declare(strict_types=1);

/** Validate and normalize destination syntax and payload structure without network resolution. */

namespace App\Services\Monitoring;

use App\Enums\Monitoring\DnsRecordType;
use InvalidArgumentException;

/** These parsers authorize no outbound destination; runtime SSRF protection remains M2.03. */
final readonly class MonitorInputSyntax
{
    /** @param MonitorInputLimits $limits Explicit application input bounds. */
    public function __construct(private MonitorInputLimits $limits)
    {
    }

    /**
     * @param string $value Hostname or IP text, never a URL.
     * @param bool $allowUnderscore Whether DNS owner labels may contain technical underscores.
     * @param bool $allowIp Whether literal IP identities are supported at this boundary.
     * @return string Lowercase ASCII hostname without a terminal dot, or canonical IP literal.
     * @throws InvalidArgumentException When syntax, IDNA conversion or length is invalid.
     */
    public function host(string $value, bool $allowUnderscore = false, bool $allowIp = true): string
    {
        $value = str_replace(["\u{3002}", "\u{FF0E}", "\u{FF61}"], '.', trim($value));

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            if (!$allowIp) {
                throw new InvalidArgumentException('A DNS name is required.');
            }

            return $this->ip($value);
        }

        if (str_ends_with($value, '.')) {
            $value = substr($value, 0, -1);
        }

        $labels = explode('.', $value);
        $normalized = [];

        foreach ($labels as $label) {
            if ($label === '') {
                throw new InvalidArgumentException('A valid DNS name is required.');
            }

            $ascii = $label;

            if (!$allowUnderscore || !str_contains($label, '_')) {
                $ascii = idn_to_ascii($label, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);
            }

            if (!is_string($ascii)) {
                throw new InvalidArgumentException('A valid DNS name is required.');
            }

            $ascii = strtolower($ascii);
            $pattern = $allowUnderscore ? '/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$/D' : '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D';

            if (strlen($ascii) > 63 || preg_match($pattern, $ascii) !== 1) {
                throw new InvalidArgumentException('A valid DNS name is required.');
            }

            $normalized[] = $ascii;
        }

        $result = implode('.', $normalized);

        if (strlen($result) > 253) {
            throw new InvalidArgumentException('The DNS name is too long.');
        }

        return $result;
    }

    /**
     * @param string $value Absolute HTTP(S) destination; path and query bytes are preserved.
     * @return string URL with normalized scheme/host and explicit port when provided.
     * @throws InvalidArgumentException When URL structure, port or byte limit is invalid.
     */
    public function url(string $value): string
    {
        $value = trim($value);

        if (strlen($value) > $this->limits->get('url_max_bytes') || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            throw new InvalidArgumentException('A valid HTTP or HTTPS URL within the byte limit is required.');
        }

        try {
            $parts = parse_url($value);
        } catch (\ValueError) {
            $parts = false;
        }

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('fragment', $parts)
        ) {
            throw new InvalidArgumentException('A valid absolute HTTP or HTTPS URL without userinfo or fragment is required.');
        }

        $rawHost = $parts['host'];

        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $rawHost = substr($rawHost, 1, -1);

            if (filter_var($rawHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('A valid bracketed IPv6 address is required.');
            }
        } elseif (str_contains($rawHost, ':')) {
            throw new InvalidArgumentException('IPv6 URL addresses require brackets.');
        }

        // Numeric legacy host forms must not be interpreted differently by a later HTTP client.
        if (preg_match('/^[0-9.]+$/D', $rawHost) === 1 && filter_var($rawHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('A canonical IPv4 address is required.');
        }

        $host = $this->host($rawHost);
        $port = $parts['port'] ?? null;

        if ($port !== null && $port < 1) {
            throw new InvalidArgumentException('A valid destination port is required.');
        }

        if (str_contains($host, ':')) {
            $host = '['.$host.']';
        }

        $result = strtolower($parts['scheme']).'://'.$host;
        $result .= $port === null ? '' : ':'.$port;
        $result .= $parts['path'] ?? '';
        $result .= array_key_exists('query', $parts) ? '?'.$parts['query'] : '';

        if (strlen($result) > $this->limits->get('url_max_bytes')) {
            throw new InvalidArgumentException('The normalized URL exceeds the byte limit.');
        }

        return $result;
    }

    /**
     * @param string $value Literal resolver or DNS address; hostnames never trigger resolution here.
     * @param int $family Optional FILTER_FLAG_IPV4 or FILTER_FLAG_IPV6 expectation.
     * @return string Canonical address text.
     * @throws InvalidArgumentException When the address is invalid or has the wrong family.
     */
    public function ip(string $value, int $family = 0): string
    {
        $value = trim($value);
        $packed = @inet_pton($value);

        if (filter_var($value, FILTER_VALIDATE_IP, $family) === false || $packed === false) {
            throw new InvalidArgumentException('A valid literal IP address is required.');
        }

        $canonical = inet_ntop($packed);

        if ($canonical === false) {
            throw new InvalidArgumentException('A valid literal IP address is required.');
        }

        return $canonical;
    }

    /**
     * @param array<array-key, mixed> $headers Submitted complete header map, including an empty map for clearing.
     * @return array<string, string> Lowercase names with exact string values.
     * @throws InvalidArgumentException When names, values, reserved fields or byte/count limits are invalid.
     */
    public function headers(array $headers): array
    {
        if (count($headers) > $this->limits->get('header_count_max')) {
            throw new InvalidArgumentException('The header count exceeds the configured limit.');
        }

        $normalized = [];
        $bytes = 0;
        $reserved = ['host', 'content-length', 'connection', 'transfer-encoding', 'te', 'trailer', 'upgrade'];

        foreach ($headers as $name => $value) {
            if (
                !is_string($name)
                || !is_string($value)
                || strlen($name) > $this->limits->get('header_name_max_bytes')
                || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1
                || strlen($value) > $this->limits->get('header_value_max_bytes')
                || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1
            ) {
                throw new InvalidArgumentException('Headers require valid names and bounded string values without control characters.');
            }

            $key = strtolower($name);

            if (isset($normalized[$key]) || in_array($key, $reserved, true) || str_starts_with($key, 'proxy-')) {
                throw new InvalidArgumentException('Duplicate or transport-reserved headers are not allowed.');
            }

            $bytes += strlen($name) + strlen($value);
            $normalized[$key] = $value;
        }

        if ($bytes > $this->limits->get('headers_max_bytes')) {
            throw new InvalidArgumentException('The combined header byte limit was exceeded.');
        }

        return $normalized;
    }

    /**
     * @param DnsRecordType $type Expected record family.
     * @param array<array-key, mixed> $values Submitted complete expectation list.
     * @return list<string> Normalized, distinct values preserving list order and exact TXT bytes.
     * @throws InvalidArgumentException When a record shape, family or configured bound is invalid.
     */
    public function dnsValues(DnsRecordType $type, array $values): array
    {
        if (
            !array_is_list($values)
            || $values === []
            || count($values) > $this->limits->get('dns_values_max')
            || ($type === DnsRecordType::CNAME && count($values) !== 1)
        ) {
            throw new InvalidArgumentException('A bounded nonempty DNS expectation list is required; CNAME accepts one value.');
        }

        $result = [];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > $this->limits->get('dns_value_max_bytes')) {
                throw new InvalidArgumentException('DNS expectations require bounded nonempty strings.');
            }

            $normalized = match ($type) {
                DnsRecordType::A => $this->ip($value, FILTER_FLAG_IPV4),
                DnsRecordType::AAAA => $this->ip($value, FILTER_FLAG_IPV6),
                DnsRecordType::CNAME => $this->host($value, allowIp: false),
                DnsRecordType::MX => $this->mx($value),
                DnsRecordType::TXT => $value,
            };

            if (in_array($normalized, $result, true)) {
                throw new InvalidArgumentException('DNS expectations must be distinct after normalization.');
            }

            $result[] = $normalized;
        }

        if ($type === DnsRecordType::MX && count($result) > 1 && in_array('0 .', $result, true)) {
            throw new InvalidArgumentException('Null MX cannot be combined with another exchange.');
        }

        return $result;
    }

    /**
     * @param string $value Application MX representation: decimal preference and exchange name.
     * @return string Canonical preference/name pair, including the explicit null MX value 0 dot.
     * @throws InvalidArgumentException When preference or exchange is invalid.
     */
    private function mx(string $value): string
    {
        if (preg_match('/^([0-9]{1,5})[ \t]+([^ \t]+)$/D', trim($value), $matches) !== 1 || (int) $matches[1] > 65535) {
            throw new InvalidArgumentException('MX values require a valid preference and exchange name.');
        }

        $preference = (int) $matches[1];

        if ($matches[2] === '.') {
            if ($preference !== 0) {
                throw new InvalidArgumentException('Null MX requires preference zero.');
            }

            return '0 .';
        }

        return $preference.' '.$this->host($matches[2], allowIp: false);
    }
}
