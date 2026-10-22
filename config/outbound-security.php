<?php

declare(strict_types=1);

/** Bound every outbound monitor and webhook connection independently of form validation. */

return [
    // Comma-separated CIDR exceptions for deliberately reachable private networks; empty denies every blocked range.
    'allowed_cidrs' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('OUTBOUND_ALLOWED_CIDRS', ''))),
        static fn (string $cidr): bool => $cidr !== '',
    )),
    // Maximum validated redirect hops a protected HTTP transport may follow for one observation.
    'max_redirects' => 5,
];
