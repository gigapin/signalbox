<?php

/** Render account identity and security status without exposing tenant-private domain data. */

namespace App\Http\Controllers;

use App\Http\Support\InvitationSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Provide account-only destinations until tenant-authorized feature routes are introduced. */
final class AccountController extends Controller
{
    /**
     * @param Request $request Verified authenticated account request.
     * @param InvitationSession $invitations Safe invitation continuation adapter.
     * @return Response Minimal identity and currently accessible team summary.
     */
    public function show(Request $request, InvitationSession $invitations): Response
    {
        $user = $this->authenticatedUser($request);

        $team = $user->resolveCurrentTeam();

        return Inertia::render('Account/Overview', [
            'hasPendingInvitation' => $invitations->resolve($request)?->isPending() ?? false,
            'currentTeam' => $team === null ? null : ['name' => $team->name]
        ]);
    }

    /**
     * @param Request $request Verified account after recent password confirmation.
     * @return Response Setup booleans only; QR and recovery data use separate no-store endpoints.
     */
    public function security(Request $request): Response
    {
        $user = $this->authenticatedUser($request);

        return Inertia::render('Account/Security', [
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null
        ]);
    }
}
