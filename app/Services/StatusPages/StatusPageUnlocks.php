<?php

declare(strict_types=1);

/** Remember in the visitor's session which password-protected status pages were unlocked. */

namespace App\Services\StatusPages;

use App\Enums\Security\KeyPurpose;
use App\Exceptions\StatusPageUnlockRateLimited;
use App\Exceptions\StatusPageUnlockUnavailable;
use App\Models\StatusPage;
use App\Services\Security\PurposeKeys;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;

/**
 * The session stores a keyed fingerprint of the current hash rather than a flag, so changing or removing and
 * setting again a password locks out every visitor who had unlocked the page.
 */
final readonly class StatusPageUnlocks
{
    /**
     * @param PurposeKeys $keys Dedicated session-proof keys and historical readers.
     * @param StatusPageUnlockLimiter $limiter Global admission and fenced completion for password verification.
     */
    public function __construct(private PurposeKeys $keys, private StatusPageUnlockLimiter $limiter)
    {
    }

    /**
     * @param Session $session Visitor session.
     * @param StatusPage $page Published page.
     * @return bool Whether the visitor may read the page: it has no password, or the current one was entered.
     */
    public function allows(Session $session, StatusPage $page): bool
    {
        if ($page->password_hash === null) {
            return true;
        }

        $stored = $session->get($this->key($page));

        if (!is_string($stored) || !$this->keys->matches($stored, KeyPurpose::PageSession, $page->id.':'.$page->password_hash, (string) $page->password_hash)) {
            return false;
        }

        // Upgrade only an authenticated proof; never reinterpret a consent digest as a new session proof.
        $session->put($this->key($page), $this->fingerprint($page));

        return true;
    }

    /**
     * Rotates the session identity when the password is right, as this grants access.
     *
     * @param Session $session Visitor session.
     * @param StatusPage $page Published page with a password.
     * @param string $password Submitted plaintext.
     * @return bool Whether the password matched.
     * @throws StatusPageUnlockRateLimited Shared admission or fenced completion refused the attempt.
     * @throws StatusPageUnlockUnavailable Shared admission state could not be trusted; no session is granted.
     */
    public function attempt(Session $session, StatusPage $page, #[\SensitiveParameter] string $password): bool
    {
        if ($page->password_hash === null) {
            return false;
        }

        $token = $this->limiter->reserve($page);
        $matched = Hash::check($password, $page->password_hash);
        $this->limiter->finish($page, $token, $matched);

        if (!$matched) {
            return false;
        }

        $session->put($this->key($page), $this->fingerprint($page));
        $session->regenerate();

        return true;
    }

    /**
     * @param StatusPage $page Page identity.
     * @return string Session key of this page.
     */
    private function key(StatusPage $page): string
    {
        return 'status_page_unlocks.'.$page->id;
    }

    /**
     * @param StatusPage $page Page with a password.
     * @return string Keyed digest of the current hash; useless for guessing the password.
     */
    private function fingerprint(StatusPage $page): string
    {
        return $this->keys->digest(KeyPurpose::PageSession, $page->id.':'.$page->password_hash);
    }
}
