<?php

/** Declare monitor pages and mutations inside an authorized route team. */

use App\Http\Controllers\Monitoring\MonitorController;
use App\Http\Controllers\Monitoring\MonitorPageController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

// Store and update paths must stay within MonitorInputTransport's byte-preserving pattern.
Route::middleware(['auth', 'verified', EstablishTeamContext::class])
    ->group(function (): void {
        // Pages carry private configuration projections, so browsers and proxies must not store them.
        Route::middleware(NoStoreAuthentication::class)
            ->group(function (): void {
                Route::get('/teams/{team}/monitors', [MonitorPageController::class, 'index'])
                    ->whereNumber('team')
                    ->name('teams.monitors.index');

                Route::get('/teams/{team}/monitors/create', [MonitorPageController::class, 'create'])
                    ->whereNumber('team')
                    ->name('teams.monitors.create');

                Route::get('/teams/{team}/monitors/{monitor}', [MonitorPageController::class, 'show'])
                    ->whereNumber(['team', 'monitor'])
                    ->name('teams.monitors.show');

                Route::get('/teams/{team}/monitors/{monitor}/edit', [MonitorPageController::class, 'edit'])
                    ->whereNumber(['team', 'monitor'])
                    ->name('teams.monitors.edit');
            });

        Route::post('/teams/{team}/monitors', [MonitorController::class, 'store'])
            ->whereNumber('team')
            ->name('teams.monitors.store');

        Route::match(['PUT', 'PATCH'], '/teams/{team}/monitors/{monitor}', [MonitorController::class, 'update'])
            ->whereNumber(['team', 'monitor'])
            ->name('teams.monitors.update');

        Route::delete('/teams/{team}/monitors/{monitor}', [MonitorController::class, 'destroy'])
            ->whereNumber(['team', 'monitor'])
            ->name('teams.monitors.destroy');

        Route::post('/teams/{team}/monitors/{monitor}/pause', [MonitorController::class, 'pause'])
            ->whereNumber(['team', 'monitor'])
            ->name('teams.monitors.pause');

        Route::post('/teams/{team}/monitors/{monitor}/resume', [MonitorController::class, 'resume'])
            ->whereNumber(['team', 'monitor'])
            ->name('teams.monitors.resume');
    });
