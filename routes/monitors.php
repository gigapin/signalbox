<?php

/** Declare monitor mutations inside an authorized route team; pages are added by M2.05. */

use App\Http\Controllers\Monitoring\MonitorController;
use App\Http\Middleware\EstablishTeamContext;
use Illuminate\Support\Facades\Route;

// Store and update paths must stay within MonitorInputTransport's byte-preserving pattern.
Route::middleware(['auth', 'verified', EstablishTeamContext::class])
    ->group(function (): void {
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
