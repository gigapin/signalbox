<?php

/** Build encrypted authenticator fixtures and clock-controlled TOTP values without external services. */

namespace Tests\Support;

use App\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/** Keep test-only security setup distinct from production HTTP enrollment. */
final class AuthFixture
{
    /**
     * @param User $user Persisted identity receiving an authenticator fixture.
     * @param bool $confirmed Whether setup has already been confirmed.
     * @return User Refreshed identity with encrypted secret and eight recovery codes.
     */
    public static function enroll(User $user, bool $confirmed = true): User
    {
        app(EnableTwoFactorAuthentication::class)($user);
        if ($confirmed) {
            $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        }

        return $user->refresh();
    }

    /**
     * @param User $user Identity whose encrypted secret provides the current code.
     * @param int $offset Number of 30-second steps away from the controlled clock.
     * @return string Six-digit token, preserving leading zeros.
     */
    public static function code(User $user, int $offset = 0): string
    {
        return app(Google2FA::class)->oathTotp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), intdiv(now()->getTimestamp(), 30) + $offset);
    }
}
