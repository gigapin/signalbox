<?php

declare(strict_types=1);

/** Bound concurrent HTTP acquisition and cumulative decoded response bytes per observation. */
return [
    'max_batch_size' => 10,
    'max_response_bytes' => 1048576,
    // Bound all A/AAAA queries and aliases performed by the synchronous outbound guard.
    'resolution_timeout_ms' => 1000,
];
