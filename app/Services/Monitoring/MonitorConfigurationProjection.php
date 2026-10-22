<?php

declare(strict_types=1);

/** Build the only configuration shape allowed to leave the server for monitor details, lists and events. */

namespace App\Services\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Services\Security\SecretRedactor;

/**
 * URL query values, header values and request bodies never appear; edit screens must ask for
 * replacements explicitly rather than repopulating private values from this projection.
 */
final readonly class MonitorConfigurationProjection
{
    /** @param SecretRedactor $redactor Shared redaction rules. */
    public function __construct(private SecretRedactor $redactor)
    {
    }

    /**
     * @param MonitorConfiguration $configuration Decrypted private configuration of an authorized monitor.
     * @return array<string, mixed> Type plus safe fields: HTTP/keyword expose a redacted URL, header names and body size;
     *                              TLS, DNS and heartbeat settings contain no secrets and are exposed unchanged.
     */
    public function project(MonitorConfiguration $configuration): array
    {
        $projection = ['type' => $configuration->type()->value];

        return match (true) {
            $configuration instanceof HttpMonitorConfiguration => [...$projection, ...$this->http($configuration)],
            $configuration instanceof KeywordMonitorConfiguration => [
                ...$projection,
                ...$this->http($configuration->http),
                'expected_keyword' => $configuration->keyword,
                'keyword_mode' => $configuration->mode->value,
            ],
            $configuration instanceof TlsMonitorConfiguration => [
                ...$projection,
                'tls_host' => $configuration->host,
                'tls_port' => $configuration->port,
                'tls_expiry_warning_days' => $configuration->expiryWarningDays,
            ],
            $configuration instanceof DnsMonitorConfiguration => [
                ...$projection,
                'dns_name' => $configuration->name,
                'dns_record_type' => $configuration->recordType->value,
                'dns_expected_values' => $configuration->expectedValues,
                'dns_resolver' => $configuration->resolver,
            ],
            $configuration instanceof HeartbeatMonitorConfiguration => [
                ...$projection,
                'heartbeat_grace_seconds' => $configuration->graceSeconds,
            ],
            default => $projection,
        };
    }

    /**
     * @param HttpMonitorConfiguration $http Private request settings.
     * @return array<string, mixed> Redacted URL, sorted header names, body presence/bytes and public response expectations.
     */
    private function http(HttpMonitorConfiguration $http): array
    {
        $headerNames = array_keys($http->headers);
        sort($headerNames);

        return [
            'url' => $this->redactor->url($http->url),
            'method' => $http->method->value,
            'header_names' => $headerNames,
            'body' => $this->redactor->body($http->body),
            'expected_status' => $http->expectedStatus,
            'follow_redirects' => $http->followRedirects,
        ];
    }
}
