<?php

/** Separate server-side Reverb publishing from browser-facing WebSocket addresses. */

return [
    // Keep infrastructure-free bootstrap available; selecting reverb requires configured credentials.
    'default' => env('BROADCAST_CONNECTION', 'log'),
    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // Compose overrides these with the internal reverb service and listener port.
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            // Limit HTTP publishing connection and response waits to seconds, with no implicit retry.
            'client_options' => ['connect_timeout' => 3, 'timeout' => 5],
        ],
        'log' => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],
];
