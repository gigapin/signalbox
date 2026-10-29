<?php

/** Declare the membership-authorized web surface without implicit tenant binding or API exposure. */

use App\Http\Controllers\Auth\InvitedRegistrationController;
use App\Http\Controllers\Teams\InvitationController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

Route::middleware(NoStoreAuthentication::class)
    ->group(function (): void {
        Route::get('/invitations/accept', [InvitationController::class, 'review'])
            ->name('invitations.review');

        Route::get('/invitations/{invitation}/{token}', [InvitationController::class, 'open'])
            ->whereNumber('invitation')
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:30,1')
            ->name('invitations.open');
        Route::post('/invitations/switch-account', [InvitationController::class, 'switchAccount'])
            ->middleware('auth')
            ->name('invitations.switch-account');

        Route::middleware('guest')
            ->group(function (): void {
                Route::get('/invitations/register', [InvitedRegistrationController::class, 'show'])
                    ->name('invitations.register');

                Route::post('/invitations/register', [InvitedRegistrationController::class, 'store'])
                    ->middleware('throttle:auth-public')
                    ->name('invitations.register.store');

            });
        Route::middleware(['auth', 'verified'])
            ->group(function (): void {
                Route::post('/invitations/accept', [InvitationController::class, 'accept'])
                    ->middleware('throttle:30,1')
                    ->name('invitations.accept');

                Route::get('/teams', [TeamController::class, 'index'])
                    ->name('teams.index');

                Route::get('/teams/{team}', [TeamController::class, 'show'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber('team')
                    ->name('teams.show');

                Route::post('/teams/{team}/select', [TeamController::class, 'select'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber('team')
                    ->name('teams.select');

                Route::patch('/teams/{team}/members/{member}', [TeamController::class, 'update'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber(['team', 'member'])
                    ->name('teams.members.update');

                Route::delete('/teams/{team}/members/{member}', [TeamController::class, 'remove'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber(['team', 'member'])
                    ->name('teams.members.destroy');

                Route::get('/teams/{team}/ownership', [TeamController::class, 'ownership'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber('team')
                    ->name('teams.ownership');

                Route::post('/teams/{team}/ownership', [TeamController::class, 'transfer'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber('team')
                    ->middleware('password.confirm')
                    ->name('teams.ownership.store');
                Route::post('/teams/{team}/invitations', [InvitationController::class, 'store'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber('team')
                    ->middleware('throttle:10,1')
                    ->name('teams.invitations.store');
                Route::post('/teams/{team}/invitations/{invitation}/resend', [InvitationController::class, 'resend'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber(['team', 'invitation'])
                    ->middleware('throttle:10,1')
                    ->name('teams.invitations.resend');
                Route::delete('/teams/{team}/invitations/{invitation}', [InvitationController::class, 'revoke'])
                    ->middleware(EstablishTeamContext::class)
                    ->whereNumber(['team', 'invitation'])
                    ->name('teams.invitations.destroy');

            });
    });
