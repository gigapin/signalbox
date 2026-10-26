<?php

/** Configure isolated Redis workers and operator-only queue observability. */

use Illuminate\Support\Str;

/** @var array<string, mixed> $worker Shared one-attempt worker limits; timeout stays below Redis retry_after (90 seconds). */
$worker = [
    'connection' => 'redis',
    'balance' => false,
    'maxProcesses' => 1,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 128,
    'tries' => 1,
    'timeout' => 30,
    'nice' => 0,
];

return [
    // Identify the dashboard and use the default Redis connection for its metadata.
    'name' => env('APP_NAME', 'Signalbox'),
    'domain' => null,
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'Signalbox'), '_').'_horizon:'),
    'middleware' => ['web'],
    // Alert thresholds are queue waiting durations in seconds.
    'waits' => ['redis:default' => 60, 'redis:checks:eu' => 60, 'redis:checks:us' => 60],
    // Retain successful job metadata for one hour and failures for one week, in minutes.
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'silenced_tags' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    // Wait for child workers to exit; the Compose grace period exceeds the worker timeout.
    'fast_termination' => false,
    'memory_limit' => 64,
    // Each simulated region has its own worker and cannot starve the other region.
    'defaults' => [
        'default' => [...$worker, 'queue' => ['default']],
        'checks-eu' => [...$worker, 'queue' => ['checks:eu'], 'timeout' => 45],
        'checks-us' => [...$worker, 'queue' => ['checks:us'], 'timeout' => 45],
    ],
    // Reuse the explicit limits in every runtime; production scaling remains an M12 decision.
    'environments' => ['*' => ['default' => [], 'checks-eu' => [], 'checks-us' => []]],
];
