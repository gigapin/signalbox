<?php

declare(strict_types=1);

/** Translate the five configuration variants into dedicated persistence columns and back. */

namespace App\Services\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\MonitorType;
use App\Models\Monitor;
use InvalidArgumentException;
use LogicException;

/** Keep storage mapping separate from DTO invariants and Eloquent transport mechanics. */
final class MonitorConfigurationMapper
{
    /** @var list<string> Every type-specific column, cleared before applying a replacement configuration. */
    public const array COLUMNS = [
        'url', 'method', 'headers_json', 'body', 'expected_status', 'follow_redirects',
        'expected_keyword', 'keyword_mode', 'tls_host', 'tls_port', 'tls_expiry_warning_days',
        'dns_name', 'dns_record_type', 'dns_expected_values', 'dns_resolver', 'heartbeat_grace_seconds',
    ];

    /**
     * @param MonitorConfiguration $configuration Complete immutable variant to apply without saving.
     * @return array<string, mixed> Attributes including explicit nulls for every irrelevant configuration column.
     * @throws InvalidArgumentException When an unsupported implementation crosses the configuration boundary.
     */
    public static function attributes(MonitorConfiguration $configuration): array
    {
        $attributes = match (true) {
            $configuration instanceof HttpMonitorConfiguration => self::httpAttributes($configuration),
            $configuration instanceof KeywordMonitorConfiguration => [
                ...self::httpAttributes($configuration->http),
                'expected_keyword' => $configuration->keyword,
                'keyword_mode' => $configuration->mode,
            ],
            $configuration instanceof TlsMonitorConfiguration => [
                'tls_host' => $configuration->host,
                'tls_port' => $configuration->port,
                'tls_expiry_warning_days' => $configuration->expiryWarningDays,
            ],
            $configuration instanceof DnsMonitorConfiguration => [
                'dns_name' => $configuration->name,
                'dns_record_type' => $configuration->recordType,
                'dns_expected_values' => $configuration->expectedValues,
                'dns_resolver' => $configuration->resolver,
            ],
            $configuration instanceof HeartbeatMonitorConfiguration => [
                'heartbeat_grace_seconds' => $configuration->graceSeconds,
            ],
            default => throw new InvalidArgumentException('Unsupported monitor configuration implementation.'),
        };

        return [
            ...array_fill_keys(self::COLUMNS, null),
            ...$attributes,
            'type' => $configuration->type(),
        ];
    }

    /**
     * @param Monitor $monitor Record with native enum, JSON and encryption casts already applied.
     * @return MonitorConfiguration Complete structurally validated configuration.
     * @throws LogicException When a required persisted field or type is missing.
     * @throws InvalidArgumentException When persisted values violate DTO invariants.
     */
    public static function configuration(Monitor $monitor): MonitorConfiguration
    {
        return match ($monitor->type) {
            MonitorType::Http => self::httpConfiguration($monitor),
            MonitorType::Keyword => new KeywordMonitorConfiguration(
                self::httpConfiguration($monitor),
                $monitor->expected_keyword ?? throw new LogicException('Missing keyword text.'),
                $monitor->keyword_mode ?? throw new LogicException('Missing keyword mode.'),
            ),
            MonitorType::Tls => new TlsMonitorConfiguration(
                $monitor->tls_host ?? throw new LogicException('Missing TLS host.'),
                $monitor->tls_port ?? throw new LogicException('Missing TLS port.'),
                $monitor->tls_expiry_warning_days,
            ),
            MonitorType::Dns => new DnsMonitorConfiguration(
                $monitor->dns_name ?? throw new LogicException('Missing DNS name.'),
                $monitor->dns_record_type ?? throw new LogicException('Missing DNS record type.'),
                $monitor->dns_expected_values ?? throw new LogicException('Missing DNS expected values.'),
                $monitor->dns_resolver,
            ),
            MonitorType::Heartbeat => new HeartbeatMonitorConfiguration(
                $monitor->heartbeat_grace_seconds ?? throw new LogicException('Missing heartbeat tolerance.'),
            ),
            default => throw new LogicException('Missing monitor type.'),
        };
    }

    /**
     * @param HttpMonitorConfiguration $http Shared HTTP/keyword transport settings.
     * @return array<string, mixed> HTTP-specific persisted attributes before irrelevant fields are cleared.
     */
    private static function httpAttributes(HttpMonitorConfiguration $http): array
    {
        return [
            'url' => $http->url,
            'method' => $http->method,
            'headers_json' => $http->headers,
            'body' => $http->body,
            'expected_status' => $http->expectedStatus,
            'follow_redirects' => $http->followRedirects,
        ];
    }

    /**
     * @param Monitor $monitor HTTP or keyword record with decrypted transport fields.
     * @return HttpMonitorConfiguration Shared transport settings without running network validation.
     * @throws LogicException When a required transport field is absent.
     * @throws InvalidArgumentException When transport structure is invalid.
     */
    private static function httpConfiguration(Monitor $monitor): HttpMonitorConfiguration
    {
        return new HttpMonitorConfiguration(
            $monitor->url ?? throw new LogicException('Missing HTTP URL.'),
            $monitor->method ?? throw new LogicException('Missing HTTP method.'),
            $monitor->headers_json ?? throw new LogicException('Missing HTTP headers.'),
            $monitor->body,
            $monitor->expected_status ?? throw new LogicException('Missing expected HTTP status.'),
            $monitor->follow_redirects ?? throw new LogicException('Missing redirect setting.'),
        );
    }
}
