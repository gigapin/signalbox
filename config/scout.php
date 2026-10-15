<?php

/** Keep searches in PostgreSQL; tenant-constrained searchable models are introduced in M2. */

return [
    // This baseline requires no external search service or background indexing.
    'driver' => 'database',
    'prefix' => '',
    'queue' => false,
    'after_commit' => true,
    // Bound import batches to 500 records if later explicitly requested from the CLI.
    'chunk' => ['searchable' => 500, 'unsearchable' => 500],
    'soft_delete' => false,
    'identify' => false,
];
