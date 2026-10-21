<?php

/** Configure only the session authentication capabilities implemented in M1.02. */

use Laravel\Fortify\Features;

return [
    // Session authentication stays independent of future Sanctum API endpoints.
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'views' => true,
    'home' => '/account',
    // Routes are application-owned; these names also govern Fortify's login pipeline.
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    'redirects' => ['logout' => '/', 'password-confirmation' => '/account/security'],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
    ],
];
