<?php

/** Bind pending second-factor login to a short-lived credential snapshot. */

namespace App\Actions\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Symfony\Component\HttpFoundation\Response;

/** Extend the existing Fortify pipeline only at the challenge creation boundary. */
class TrackTwoFactorChallenge extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * Record a bounded challenge after Fortify verifies the password.
     * @param Request $request Browser login request with a server-managed session.
     * @param User $user Identity whose password has just been verified.
     * @return Response Fortify's challenge redirect or JSON response.
     */
    protected function twoFactorChallengeResponse($request, $user): Response
    {
        $request->session()->regenerate();
        $request->session()->put([
            'login.started_at' => now()->getTimestamp(),
            'login.fingerprint' => hash('sha256', $user->password.'|'.$user->two_factor_secret),
        ]);

        return parent::twoFactorChallengeResponse($request, $user);
    }
}
