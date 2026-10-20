<?php

/** Adapt Fortify TOTP verification to the application clock and secret-scoped replay tracking. */

namespace App\Services\Auth;

use Laravel\Fortify\TwoFactorAuthenticationProvider;

/** Retain the maintained TOTP algorithm while isolating replay state between identities. */
class ScopedTwoFactorProvider extends TwoFactorAuthenticationProvider
{
    /**
     * Verify a six-digit token once per authenticator time step under the caller's user row lock.
     * @param string $secret Decrypted authenticator secret used only in memory.
     * @param string $code User-supplied six-digit token; never logged or used as a global cache identity.
     * @return bool Whether the token belongs to an unused step within the one-step tolerance.
     */
    public function verify($secret, $code): bool
    {
        /** Opaque secret-specific identity, preventing cross-user six-digit collisions. */
        $key = 'auth.totp.'.hash('sha256', $secret);
        /** @var mixed $previous Previously consumed step, if the shared cache still retains it. */
        $previous = $this->cache?->get($key);
        /** @var int|bool $step Google2FA's verified time-step result or false for invalid/replayed tokens. */
        $step = $this->engine->verifyKeyNewer($secret, $code, is_numeric($previous) ? (int) $previous : 0, 1, intdiv(now()->getTimestamp(), 30));
        if ($step === false) {
            return false;
        }
        // Normalize counters at both sides of caches that serialize integer values as strings.
        $this->cache?->put($key, $step === true ? intdiv(now()->getTimestamp(), 30) : (int) $step, 120);

        return true;
    }
}
