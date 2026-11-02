<?php

declare(strict_types=1);

/** Bound DNS ownership leases and restrict the internal TLS authorization endpoint to trusted peers. */

return [
    // Keep the TXT proof in DNS; routing fails closed at this exclusive UTC expiry.
    'lease_seconds' => 86400,
    // Recheck at most once an hour; the bounded scheduler pass visits the oldest attempts first.
    'recheck_seconds' => 3600,
    // One cumulative deadline, including aliases and TCP continuation; no HTTP probe or selected resolver.
    'dns_timeout_ms' => 2000,
    // Compare raw socket peers, never forwarded client IP headers. Configure exact proxy peers for deployment.
    'tls_peers' => array_values(array_filter(explode(',', (string) env('STATUS_DOMAIN_TLS_PEERS', '127.0.0.1,::1')))),
    // Explicit reverse proxy IPs/CIDRs. Forwarded Host is deliberately excluded even for these peers.
    'proxy_ips' => array_values(array_filter(explode(',', (string) env('TRUSTED_PROXY_IPS', '')))),
];
