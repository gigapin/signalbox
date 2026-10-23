<?php

/** Declare the platform admin panel; every route answers 404 to anyone but a platform operator. */

use App\Http\Controllers\Admin\TeamDirectoryController;
use App\Http\Controllers\Admin\TeamPlanOverrideController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

// Operator pages show cross-team data, so they are never cached; changes also need a recent password.
Route::middleware(['auth', 'verified', EnsurePlatformAdmin::class, NoStoreAuthentication::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::redirect('/', '/admin/teams')->name('home');

        Route::get('/teams', [TeamDirectoryController::class, 'index'])->name('teams.index');

        Route::get('/teams/{team}', [TeamDirectoryController::class, 'show'])
            ->whereNumber('team')
            ->name('teams.show');

        Route::middleware('password.confirm')->group(function (): void {
            Route::put('/teams/{team}/plan-override', [TeamPlanOverrideController::class, 'update'])
                ->whereNumber('team')
                ->name('teams.plan-override.update');

            Route::delete('/teams/{team}/plan-override', [TeamPlanOverrideController::class, 'destroy'])
                ->whereNumber('team')
                ->name('teams.plan-override.destroy');
        });
    });
