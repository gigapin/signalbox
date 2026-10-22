<?php

declare(strict_types=1);

/** Supply complete public input for all five monitor types without touching persistence. */

namespace Tests\Support;

/** Use reserved destinations and synthetic secrets while exercising nondefault byte-sensitive values. */
final class MonitorInputFixture
{
    /** @return array<string, mixed> Valid public HTTP input with explicit POST body and meaningful whitespace. */
    public static function http(): array
    {
        return [
            'name' => ' Synthetic input ',
            'type' => 'http',
            'interval_seconds' => '300',
            'timeout_ms' => 5000,
            'url' => 'HTTPS://Example.Invalid/Path?key=synthetic-secret',
            'method' => 'post',
            'headers' => ['Authorization' => ' Bearer synthetic-secret ', 'X.Probe' => ''],
            'body' => ' exact body ',
        ];
    }

    /** @return array<string, array{array<string, mixed>}> Valid input variants with shared cadence and timeout. */
    public static function cases(): array
    {
        $common = ['name' => 'Probe', 'interval_seconds' => 300, 'timeout_ms' => 5000];

        return [
            'http' => [self::http()],
            'keyword' => [[...self::http(), 'type' => 'keyword', 'expected_keyword' => ' exact text ', 'keyword_mode' => 'absent']],
            'tls' => [[...$common, 'type' => 'tls', 'tls_host' => 'Bücher.Example.', 'tls_port' => 443]],
            'dns' => [[...$common, 'type' => 'dns', 'dns_name' => '_DMARC.Example.', 'dns_record_type' => 'TXT', 'dns_expected_values' => [' exact TXT ']]],
            'heartbeat' => [[...$common, 'type' => 'heartbeat', 'heartbeat_grace_seconds' => 0]],
        ];
    }
}
