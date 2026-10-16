<?php

/** Configure bounded API tokens; Inertia browser authentication continues to use web sessions. */

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [
    // Stateful API middleware is not enabled; no separate cross-origin SPA is introduced.
    'stateful' => [],
    'guard' => ['web'],
    // A token expires after 24 hours by default; an earlier explicit expires_at still wins.
    'expiration' => (int) env('SANCTUM_EXPIRATION', 1440),
    'token_prefix' => 'signalbox_',
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
