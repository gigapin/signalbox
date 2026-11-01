<?php

/** Declare status page management inside an authorized route team, and the public pages outside any team. */

use App\Http\Controllers\StatusPages\PublicStatusPageController;
use App\Http\Controllers\StatusPages\StatusPageController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use App\Models\StatusPage;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', EstablishTeamContext::class])
    ->group(function (): void {
        Route::get('/teams/{team}/status-pages', [StatusPageController::class, 'index'])
            ->whereNumber('team')
            ->name('teams.status-pages.index');

        Route::post('/teams/{team}/status-pages', [StatusPageController::class, 'store'])
            ->whereNumber('team')
            ->name('teams.status-pages.store');

        Route::patch('/teams/{team}/status-pages/{statusPage}', [StatusPageController::class, 'update'])
            ->whereNumber(['team', 'statusPage'])
            ->name('teams.status-pages.update');

        Route::delete('/teams/{team}/status-pages/{statusPage}', [StatusPageController::class, 'destroy'])
            ->whereNumber(['team', 'statusPage'])
            ->name('teams.status-pages.destroy');
    });

// A protected page's content must never be stored by a browser or an intermediary.
Route::middleware(NoStoreAuthentication::class)
    ->group(function (): void {
        Route::get('/status/{slug}', [PublicStatusPageController::class, 'show'])
            ->where('slug', StatusPage::SLUG_PATTERN)
            ->name('status-pages.public');

        Route::post('/status/{slug}/unlock', [PublicStatusPageController::class, 'unlock'])
            ->where('slug', StatusPage::SLUG_PATTERN)
            ->middleware('throttle:status-page-unlock')
            ->name('status-pages.unlock');
    });
