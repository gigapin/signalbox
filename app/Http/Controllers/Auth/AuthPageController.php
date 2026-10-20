<?php

/** Render the explicitly declared authentication pages with minimal, non-secret props. */

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Select a known page from route identity rather than user-supplied component names. */
final class AuthPageController
{
    /**
     * @param Request $request Route and session information for the authentication screen.
     * @return Response|\Illuminate\Http\RedirectResponse Inertia page without credentials or 2FA secrets.
     */
    public function __invoke(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        /** @var string $page Explicit component associated with the application-owned named route. */
        $page = match ($request->route()?->getName()) {
            'register' => 'Auth/Register',
            'login' => 'Auth/Login',
            'password.request' => 'Auth/ForgotPassword',
            'password.reset' => 'Auth/ResetPassword',
            'verification.notice' => 'Auth/VerifyEmail',
            'password.confirm' => 'Auth/ConfirmPassword',
            'two-factor.login' => 'Auth/TwoFactorChallenge',
            default => abort(404),
        };
        if ($request->routeIs('verification.notice') && $request->user() instanceof User && $request->user()->hasVerifiedEmail()) {
            return redirect()->route('account');
        }

        if ($request->routeIs('login')) {
            $request->session()->forget('login');
        }

        return Inertia::render($page, $request->routeIs('password.reset') ? [
            'token' => $request->route('token'),
            'email' => $request->string('email')->toString(),
        ] : []);
    }
}
