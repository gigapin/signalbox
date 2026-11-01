<?php

declare(strict_types=1);

/** Remember in the visitor's session which password-protected status pages were unlocked. */

namespace App\Services\StatusPages;

use App\Models\StatusPage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;

/**
 * The session stores a keyed fingerprint of the current hash rather than a flag, so changing or removing and
 * setting again a password locks out every visitor who had unlocked the page.
 */
final readonly class StatusPageUnlocks
{
    /** @param Repository $config Application configuration holding the signing key. */
    public function __construct(private Repository $config)
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

        return is_string($stored) && hash_equals($this->fingerprint($page), $stored);
    }

    /**
     * Rotates the session identity when the password is right, as this grants access.
     *
     * @param Session $session Visitor session.
     * @param StatusPage $page Published page with a password.
     * @param string $password Submitted plaintext.
     * @return bool Whether the password matched.
     */
    public function attempt(Session $session, StatusPage $page, #[\SensitiveParameter] string $password): bool
    {
        if ($page->password_hash === null || !Hash::check($password, $page->password_hash)) {
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
        return hash_hmac('sha256', (string) $page->password_hash, (string) $this->config->get('app.key'));
    }
}
