<?php

/** Declare incident pages, incident changes and monitor maintenance windows inside an authorized route team. */

use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\Incidents\IncidentPageController;
use App\Http\Controllers\Incidents\MaintenanceWindowController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

// Nested identifiers are scoped bindings: an entry or window must belong to the parent in the URL.
Route::middleware(['auth', 'verified', EstablishTeamContext::class])
    ->group(function (): void {
        // Pages carry private timelines and declared causes, so browsers and proxies must not store them.
        Route::middleware(NoStoreAuthentication::class)
            ->group(function (): void {
                Route::get('/teams/{team}/incidents', [IncidentPageController::class, 'index'])
                    ->whereNumber('team')
                    ->name('teams.incidents.index');

                Route::get('/teams/{team}/incidents/{incident}', [IncidentPageController::class, 'show'])
                    ->whereNumber(['team', 'incident'])
                    ->name('teams.incidents.show');
            });

        Route::post('/teams/{team}/incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])
            ->whereNumber(['team', 'incident'])
            ->name('teams.incidents.acknowledge');

        Route::post('/teams/{team}/incidents/{incident}/updates', [IncidentController::class, 'storeUpdate'])
            ->whereNumber(['team', 'incident'])
            ->name('teams.incidents.updates.store');

        Route::post('/teams/{team}/incidents/{incident}/updates/{update}/publish', [IncidentController::class, 'publish'])
            ->whereNumber(['team', 'incident', 'update'])
            ->scopeBindings()
            ->name('teams.incidents.updates.publish');

        Route::patch('/teams/{team}/incidents/{incident}/severity', [IncidentController::class, 'severity'])
            ->whereNumber(['team', 'incident'])
            ->name('teams.incidents.severity');

        Route::patch('/teams/{team}/incidents/{incident}/root-cause', [IncidentController::class, 'rootCause'])
            ->whereNumber(['team', 'incident'])
            ->name('teams.incidents.root-cause');

        Route::post('/teams/{team}/monitors/{monitor}/maintenance', [MaintenanceWindowController::class, 'store'])
            ->whereNumber(['team', 'monitor'])
            ->name('teams.monitors.maintenance.store');

        Route::patch('/teams/{team}/monitors/{monitor}/maintenance/{maintenanceWindow}', [MaintenanceWindowController::class, 'update'])
            ->whereNumber(['team', 'monitor', 'maintenanceWindow'])
            ->scopeBindings()
            ->name('teams.monitors.maintenance.update');

        Route::delete('/teams/{team}/monitors/{monitor}/maintenance/{maintenanceWindow}', [MaintenanceWindowController::class, 'destroy'])
            ->whereNumber(['team', 'monitor', 'maintenanceWindow'])
            ->scopeBindings()
            ->name('teams.monitors.maintenance.destroy');
    });
