<?php

declare(strict_types=1);

/** Gate the delivery pipeline until a real checker consumer is installed; durations are seconds. */
return [
    'enabled' => env('MONITOR_SCHEDULING_ENABLED', false),
    'chunk_size' => 200,
    'publication_lease_seconds' => 10,
    'redelivery_seconds' => 5,
];
