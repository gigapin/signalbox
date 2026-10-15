<?php

/** Configure the WebSocket listener, restricted browser origins and bounded message sizes. */

return [
    'default' => 'reverb',
    'servers' => [
        'reverb' => [
            // Bind inside the container; host publication is separately restricted by Compose.
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('REVERB_SERVER_PORT', 8080),
            'path' => '',
            'hostname' => env('REVERB_HOST', '127.0.0.1'),
            'options' => ['tls' => []],
            'max_request_size' => 10000,
            // One local listener needs no cross-server Redis pub/sub connection.
            'scaling' => ['enabled' => false],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [
            [
                // Credentials remain private runtime values; none are generated in versioned files.
                'app_id' => env('REVERB_APP_ID'),
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'options' => [
                    'host' => env('REVERB_HOST', '127.0.0.1'),
                    'port' => (int) env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                // Reverb checks hostnames, not URL schemes or ports; an empty value denies all origins.
                'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('REVERB_ALLOWED_ORIGINS', 'localhost,127.0.0.1'))))),
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_connections' => 100,
                'max_message_size' => 10000,
                // Browser clients cannot publish events; authenticated server publishing is separate.
                'accept_client_events_from' => 'none',
                'rate_limiting' => [
                    'enabled' => true,
                    'max_attempts' => 60,
                    'decay_seconds' => 60,
                    'terminate_on_limit' => true,
                ],
            ],
        ],
    ],
];
