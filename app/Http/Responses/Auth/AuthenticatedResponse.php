<?php

/** Complete session authentication without exposing account or challenge secrets. */

namespace App\Http\Responses\Auth;

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/** Record revocation state immediately for password-only and second-factor sessions. */
final class AuthenticatedResponse implements LoginResponse, TwoFactorLoginResponse
{
    /**
     * @param \Illuminate\Http\Request $request Successfully authenticated session request.
     * @return Response Account destination or a minimal JSON acknowledgement.
     */
    public function toResponse($request): Response
    {
        $request->session()->forget('login');
        $request->session()->put('password_hash_web', Auth::user()?->getAuthPassword());

        return $request->wantsJson() ? response()->json(['two_factor' => false]) : redirect()->intended('/account');
    }
}
