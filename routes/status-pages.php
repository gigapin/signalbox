<?php

/** Declare status page management inside an authorized route team, and the public pages outside any team. */

use App\Http\Controllers\StatusPages\PublicStatusPageController;
use App\Http\Controllers\StatusPages\StatusDomainController;
use App\Http\Controllers\StatusPages\StatusPageController;
use App\Http\Controllers\StatusPages\StatusSubscriptionController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use App\Http\Middleware\StatusPageIndexing;
use App\Models\StatusPage;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class])
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

        Route::put('/teams/{team}/status-pages/{statusPage}/domain', [StatusDomainController::class, 'update'])
            ->whereNumber(['team', 'statusPage'])->name('teams.status-pages.domain.update');
        Route::post('/teams/{team}/status-pages/{statusPage}/domain/verify', [StatusDomainController::class, 'verify'])
            ->whereNumber(['team', 'statusPage'])->middleware('throttle:status-domain-verify')->name('teams.status-pages.domain.verify');
        Route::delete('/teams/{team}/status-pages/{statusPage}/domain', [StatusDomainController::class, 'destroy'])
            ->whereNumber(['team', 'statusPage'])->name('teams.status-pages.domain.destroy');
    });

// A protected page's content must never be stored by a browser or an intermediary.
Route::middleware([NoStoreAuthentication::class, StatusPageIndexing::class])
    ->group(function (): void {
        Route::get('/status/{slug}', [PublicStatusPageController::class, 'show'])
            ->where('slug', StatusPage::SLUG_PATTERN)
            ->name('status-pages.public');

        Route::post('/status/{slug}/unlock', [PublicStatusPageController::class, 'unlock'])
            ->where('slug', StatusPage::SLUG_PATTERN)
            ->middleware('throttle:status-page-unlock')
            ->name('status-pages.unlock');

        Route::post('/status/{slug}/subscribe', [StatusSubscriptionController::class, 'store'])
            ->where('slug', StatusPage::SLUG_PATTERN)
            ->middleware('throttle:status-page-subscribe')
            ->name('status-pages.subscribe');

        Route::match(['GET', 'POST'], '/status/subscriptions/{teamId}/{pageId}/{subscriberId}/{generation}/{action}', [StatusSubscriptionController::class, 'action'])
            ->whereNumber(['teamId', 'pageId', 'subscriberId'])
            ->whereUuid('generation')
            ->whereIn('action', ['verify', 'unsubscribe'])
            ->middleware(['signed:relative', 'throttle:60,1'])
            ->name('status-subscriptions.action');
    });
