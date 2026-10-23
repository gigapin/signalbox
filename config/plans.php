<?php

declare(strict_types=1);

/**
 * Single definition of plan entitlements adopted in ADR-010; UI, API, CLI and jobs read it through PlanLimits.
 * Every value is required and positive: a missing entry is a configuration error, never an unlimited allowance.
 */

return [
    'free' => [
        'monitors' => 5,
        'min_interval_seconds' => 300,
        'status_pages' => 1,
        'rollup_retention_days' => 90,
    ],
    'pro' => [
        'monitors' => 50,
        'min_interval_seconds' => 60,
        'status_pages' => 3,
        'rollup_retention_days' => 365,
    ],
    'business' => [
        'monitors' => 200,
        'min_interval_seconds' => 30,
        'status_pages' => 10,
        'rollup_retention_days' => 730,
    ],
    // Operator testing profile from an unlimited override (PLATFORM-ADMIN.md): high but finite, and the
    // 30-second interval equals the input validator's floor, so no cadence below it can be reached.
    'internal' => [
        'monitors' => 1000,
        'min_interval_seconds' => 30,
        'status_pages' => 100,
        'rollup_retention_days' => 730,
    ],
];
