<?php

declare(strict_types=1);

/** Bound public heartbeat traffic independently of future authenticated API plan limits. */
return [
    // Apply this limit before attempting to discover a credential's owner.
    'requests_per_ip_per_minute' => 600,
    // Apply this limit only after resolving a valid dedicated credential.
    'requests_per_credential_per_minute' => 120,
];
