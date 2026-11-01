<?php

/** Expose a read-only dashboard and monthly reports behind the same verified membership and tenant context as monitor pages. */

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Reports\MonthlyReportController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/teams/{team}/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class])
    ->whereNumber('team')
    ->name('teams.dashboard');

// Rendering a document is expensive, so downloads have their own limit per user.
Route::get('/teams/{team}/reports/{month}', MonthlyReportController::class)
    ->middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class, 'throttle:reports'])
    ->whereNumber('team')
    ->where('month', '[0-9]{4}-(0[1-9]|1[0-2])')
    ->name('teams.reports.monthly');
