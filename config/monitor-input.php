<?php

declare(strict_types=1);

/** Bound monitor configuration input independently of plan entitlements and network execution. */

return [
    // Cadence and time budgets use seconds and milliseconds respectively; plan minimums are M2.04.
    'interval_min_seconds' => 30,
    'interval_max_seconds' => 86400,
    'timeout_min_ms' => 100,
    'timeout_max_ms' => 10000,
    'threshold_max' => 10,
    // Payload limits count bytes rather than Unicode characters.
    'url_max_bytes' => 2048,
    'body_max_bytes' => 65536,
    'keyword_max_bytes' => 4096,
    'header_count_max' => 20,
    'header_name_max_bytes' => 128,
    'header_value_max_bytes' => 4096,
    'headers_max_bytes' => 16384,
    'dns_values_max' => 32,
    'dns_value_max_bytes' => 4096,
    'tls_warning_max_days' => 3650,
    'heartbeat_grace_max_seconds' => 86400,
];
