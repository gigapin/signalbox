<?php

declare(strict_types=1);

/** Supply independent complete monitor variants without network access or database setup. */

namespace Tests\Support;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\KeywordMode;

/** Use only reserved addresses and synthetic credentials, with nondefault values to expose mapping errors. */
final class MonitorConfigurationFixture
{
    /** @return array<string, array{MonitorConfiguration}> Five structurally distinct configurations for Pest datasets. */
    public static function cases(): array
    {
        $http = new HttpMonitorConfiguration(
            'https://example.invalid/health?key=synthetic-url-secret',
            HttpMethod::POST,
            ['Authorization' => 'Bearer synthetic-header-secret', 'X-Probe' => 'fixture'],
            'synthetic-body-secret',
            201,
            true,
        );

        return [
            'http' => [$http],
            'keyword' => [new KeywordMonitorConfiguration($http, ' exact text ', KeywordMode::Absent)],
            'tls' => [new TlsMonitorConfiguration('example.invalid', 8443, 21)],
            'dns' => [new DnsMonitorConfiguration('example.invalid', DnsRecordType::TXT, ['exact text', 'other text'], '192.0.2.53')],
            'heartbeat' => [new HeartbeatMonitorConfiguration(17)],
        ];
    }
}
