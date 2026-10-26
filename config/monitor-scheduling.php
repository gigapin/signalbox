<?php

declare(strict_types=1);

/** Keep the integrated monitoring pipeline opt-in; delivery durations are seconds. */
return [
    'enabled' => env('MONITOR_SCHEDULING_ENABLED', false),
    'chunk_size' => 200,
    'publication_lease_seconds' => 10,
    'redelivery_seconds' => 5,
    // Two observations fit the 45-second regional worker even with sequential HTTP DNS authorization.
    'batch_size' => 2,
];
