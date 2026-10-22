<?php

/**
 * Configure framework routing and the Inertia web middleware stack.
 */

use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/**
 * Configure web authentication, tenant binding order and token ability middleware.
 *
 * @param Middleware $middleware Framework middleware configuration builder.
 */
$configureMiddleware = function (Middleware $middleware): void {
    $middleware->web(append: [HandleInertiaRequests::class]);
    $middleware->authenticateSessions();
    $middleware->prependToPriorityList(SubstituteBindings::class, EstablishTeamContext::class);
    $middleware->redirectGuestsTo('/login');
    $middleware->redirectUsersTo('/account');

    // Register ability enforcement for future token routes without adding public API endpoints.
    $middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class]);
};

/**
 * Prevent second-factor credentials from being flashed after validation errors.
 *
 * @param Exceptions $exceptions Framework exception and validation configuration builder.
 */
$configureExceptions = function (Exceptions $exceptions): void {
    $exceptions->dontFlash(['code', 'recovery_code', 'two_factor_secret', 'two_factor_recovery_codes']);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware($configureMiddleware)
    ->withExceptions($configureExceptions)
    ->create();
