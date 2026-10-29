<?php

/** Declare notification channel, escalation policy and monitor escalation routes inside an authorized route team. */

use App\Http\Controllers\Notifications\EscalationPolicyController;
use App\Http\Controllers\Notifications\NotificationChannelController;
use App\Http\Controllers\Notifications\NotificationPageController;
use App\Http\Middleware\EstablishTeamContext;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', EstablishTeamContext::class])
    ->group(function (): void {
        // The page carries destinations and editable channel values; the signing secret response is a credential.
        Route::middleware(NoStoreAuthentication::class)
            ->group(function (): void {
                Route::get('/teams/{team}/notifications', [NotificationPageController::class, 'index'])
                    ->whereNumber('team')
                    ->name('teams.notifications.index');

                Route::post('/teams/{team}/notification-channels/{channel}/signing-secret', [NotificationChannelController::class, 'rotateSigningSecret'])
                    ->whereNumber(['team', 'channel'])
                    ->name('teams.notification-channels.signing-secret');
            });

        Route::post('/teams/{team}/notification-channels', [NotificationChannelController::class, 'store'])
            ->whereNumber('team')
            ->name('teams.notification-channels.store');

        Route::patch('/teams/{team}/notification-channels/{channel}', [NotificationChannelController::class, 'update'])
            ->whereNumber(['team', 'channel'])
            ->name('teams.notification-channels.update');

        Route::delete('/teams/{team}/notification-channels/{channel}', [NotificationChannelController::class, 'destroy'])
            ->whereNumber(['team', 'channel'])
            ->name('teams.notification-channels.destroy');

        Route::post('/teams/{team}/notification-channels/{channel}/verification', [NotificationChannelController::class, 'sendVerification'])
            ->whereNumber(['team', 'channel'])
            ->name('teams.notification-channels.verification.store');

        Route::post('/teams/{team}/notification-channels/{channel}/verification/confirm', [NotificationChannelController::class, 'confirmVerification'])
            ->whereNumber(['team', 'channel'])
            ->name('teams.notification-channels.verification.confirm');

        Route::patch('/teams/{team}/notification-channels/{channel}/activation', [NotificationChannelController::class, 'activation'])
            ->whereNumber(['team', 'channel'])
            ->name('teams.notification-channels.activation');

        Route::post('/teams/{team}/escalation-policies', [EscalationPolicyController::class, 'store'])
            ->whereNumber('team')
            ->name('teams.escalation-policies.store');

        Route::patch('/teams/{team}/escalation-policies/{policy}', [EscalationPolicyController::class, 'update'])
            ->whereNumber(['team', 'policy'])
            ->name('teams.escalation-policies.update');

        Route::delete('/teams/{team}/escalation-policies/{policy}', [EscalationPolicyController::class, 'destroy'])
            ->whereNumber(['team', 'policy'])
            ->name('teams.escalation-policies.destroy');

        Route::patch('/teams/{team}/monitors/{monitor}/escalation-policy', [EscalationPolicyController::class, 'assign'])
            ->whereNumber(['team', 'monitor'])
            ->name('teams.monitors.escalation-policy');
    });
