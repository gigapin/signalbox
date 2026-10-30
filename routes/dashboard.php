<?php

/** Expose a read-only dashboard behind the same verified membership and tenant context as monitor pages. */

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/teams/{team}/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class])
    ->whereNumber('team')
    ->name('teams.dashboard');
