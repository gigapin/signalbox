<?php

/** Render account identity and security status without exposing tenant-private domain data. */

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Provide account-only destinations until tenant-authorized feature routes are introduced. */
final class AccountController
{
    /**
     * @param Request $request Verified authenticated account request.
     * @return Response Minimal identity and currently accessible team summary.
     */
    public function show(Request $request): Response
    {
        /** @var User $user Identity already required by route middleware. */
        $user = $request->user();
        /** @var \App\Models\Team|null $team Persisted preference resolved through current membership only. */
        $team = $user->resolveCurrentTeam();

        return Inertia::render('Account/Overview', ['currentTeam' => $team === null ? null : ['name' => $team->name]]);
    }

    /**
     * @param Request $request Verified account after recent password confirmation.
     * @return Response Setup booleans only; QR and recovery data use separate no-store endpoints.
     */
    public function security(Request $request): Response
    {
        /** @var User $user Identity owning this security configuration. */
        $user = $request->user();

        return Inertia::render('Account/Security', ['twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(), 'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null]);
    }
}
