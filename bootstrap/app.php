<?php

/**
 * Configure framework routing and the Inertia web middleware stack.
 */

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(
        /**
         * Attach the Inertia protocol middleware after the default web middleware.
         *
         * @param Middleware $middleware Framework middleware configuration builder.
         */
        function (Middleware $middleware): void {
            $middleware->web(append: [HandleInertiaRequests::class]);
            // Register ability enforcement for future token routes without adding public API endpoints.
            $middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class]);
        },
    )
    ->withExceptions()
    ->create();
