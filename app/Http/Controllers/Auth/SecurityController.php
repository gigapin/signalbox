<?php

/** Apply application Form Requests before invoking Fortify's password and confirmation controllers. */

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\SecurityInputRequest;
use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

/** Retain Fortify response contracts and input validation at explicit application boundaries. */
final class SecurityController
{
    /**
     * @param SecurityInputRequest $request Validated reset token and new password.
     * @param NewPasswordController $controller Fortify broker adapter.
     * @return Responsable Reset result.
     */
    public function reset(SecurityInputRequest $request, NewPasswordController $controller): Responsable
    {
        return DB::transaction(/** Serialize token validation, password change and token deletion on the same identity row. */ function () use ($request, $controller): Responsable {
            User::query()->where('email', $request->string('email')->toString())->lockForUpdate()->first();

            return $controller->store($request);
        });
    }

    /**
     * @param SecurityInputRequest $request Validated current-password confirmation.
     * @param ConfirmablePasswordController $controller Fortify session confirmation adapter.
     * @return Responsable Confirmation result.
     */
    public function password(SecurityInputRequest $request, ConfirmablePasswordController $controller): Responsable
    {
        return $controller->store($request);
    }

    /**
     * @param SecurityInputRequest $request Validated six-digit authenticator code.
     * @param ConfirmedTwoFactorAuthenticationController $controller Fortify setup confirmation adapter.
     * @param ConfirmTwoFactorAuthentication $confirm Fortify setup verifier.
     * @return Responsable Setup result.
     */
    public function twoFactor(SecurityInputRequest $request, ConfirmedTwoFactorAuthenticationController $controller, ConfirmTwoFactorAuthentication $confirm): Responsable
    {
        return $controller->store($request, $confirm);
    }
}
