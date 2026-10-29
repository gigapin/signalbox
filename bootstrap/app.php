<?php

/**
 * Configure framework routing and the Inertia web middleware stack.
 */

use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Support\MonitorInputTransport;
use App\Services\Monitoring\MonitorConfigurationMapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/**
 * Preserve exact monitor payload bytes until the dedicated normalizer handles each field.
 *
 * @param Request $request Request examined before routing and input transformation.
 * @return bool Whether standard trimming and empty-to-null conversion must be skipped.
 */
$preserveMonitorInput = static fn (Request $request): bool => MonitorInputTransport::matches($request);

/**
 * Configure web authentication, tenant binding order and token ability middleware.
 *
 * @param Middleware $middleware Framework middleware configuration builder.
 */
$configureMiddleware = function (Middleware $middleware) use ($preserveMonitorInput): void {
    $middleware->trimStrings(except: [$preserveMonitorInput]);
    $middleware->convertEmptyStringsToNull(except: [$preserveMonitorInput]);
    $middleware->web(append: [HandleInertiaRequests::class]);
    $middleware->authenticateSessions();
    $middleware->prependToPriorityList(SubstituteBindings::class, EstablishTeamContext::class);
    $middleware->redirectGuestsTo('/login');
    $middleware->redirectUsersTo('/account');

    // Register ability enforcement for future token routes without adding public API endpoints.
    $middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class]);
};

/**
 * Prevent second-factor credentials, private monitor configuration, notification channel configuration and
 * verification codes from being flashed after validation errors, including domain refusals rethrown by controllers.
 *
 * @param Exceptions $exceptions Framework exception and validation configuration builder.
 */
$configureExceptions = function (Exceptions $exceptions): void {
    $exceptions->dontFlash([
        'code', 'recovery_code', 'two_factor_secret', 'two_factor_recovery_codes', 'credential', 'token', 'token_hash',
        ...MonitorConfigurationMapper::COLUMNS, 'headers', 'config',
    ]);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            // Dedicated Bearer ingress deliberately runs outside web sessions, Inertia and CSRF.
            require __DIR__.'/../routes/heartbeats.php';
        },
    )
    ->withMiddleware($configureMiddleware)
    ->withExceptions($configureExceptions)
    ->create();
