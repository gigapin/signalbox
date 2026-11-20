<?php

/** Expose a read-only dashboard and monthly reports behind the same verified membership and tenant context as monitor pages. */

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Reports\MonthlyReportController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', \App\Http\Controllers\Dashboard\DashboardEntryController::class)
    ->middleware(['auth', 'verified', NoStoreAuthentication::class])
    ->name('dashboard');

Route::get('/teams/{team}/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class])
    ->whereNumber('team')
    ->name('teams.dashboard');

Route::get('/teams/{team}/reports', [MonthlyReportController::class, 'index'])
    ->middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class])
    ->whereNumber('team')
    ->name('teams.reports.index');

// Custom periods retain the monthly endpoint's authorization and shared download budget.
Route::get('/teams/{team}/reports/download', [MonthlyReportController::class, 'download'])
    ->middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class, 'throttle:reports'])
    ->whereNumber('team')
    ->name('teams.reports.download');

// Rendering a document is expensive, so downloads have their own limit per user.
Route::get('/teams/{team}/reports/{month}', MonthlyReportController::class)
    ->middleware(['auth', 'verified', EstablishTeamContext::class, NoStoreAuthentication::class, 'throttle:reports'])
    ->whereNumber('team')
    ->where('month', '[0-9]{4}-(0[1-9]|1[0-2])')
    ->name('teams.reports.monthly');
