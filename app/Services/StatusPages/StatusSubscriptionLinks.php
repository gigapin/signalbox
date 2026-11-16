<?php

declare(strict_types=1);

/** Create signed, revocable mailbox capabilities using the configured public origin. */

namespace App\Services\StatusPages;

use App\Enums\Security\KeyPurpose;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Services\Security\PurposeKeys;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\URL;

/** Relative signatures avoid dependence on request hosts; outgoing links always use APP_URL. */
final class StatusSubscriptionLinks
{
    /** @param PurposeKeys $keys Consent and URL keyring with explicit historical readers. */
    public function __construct(private readonly PurposeKeys $keys)
    {
    }

    /**
     * @param StatusPageSubscriber $subscriber Recipient and consent generation.
     * @param string $action Verification expires in one hour; unsubscribe remains valid until generation rotation.
     * @return string Signed capability without an email address or private page content.
     */
    public function action(StatusPageSubscriber $subscriber, string $action): string
    {
        $path = URL::signedRoute('status-subscriptions.action', [
            'teamId' => $subscriber->team_id,
            'pageId' => $subscriber->status_page_id,
            'subscriberId' => $subscriber->id,
            'generation' => $subscriber->generation,
            'action' => $action,
        ], $action === 'verify' ? $subscriber->verification_expires_at : null, absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * @param StatusPage $page Live page whose password controls consent admission.
     * @return string Keyed fingerprint, including the absence of a password.
     */
    public function accessHash(StatusPage $page): string
    {
        return $this->keys->digest(KeyPurpose::SubscriberConsent, $page->id.':'.$page->password_hash);
    }

    /**
     * @param StatusPage $page Live identity and password version, including public pages without a password.
     * @return list<string> Equivalent password-consent proofs across configured current and historical keys.
     */
    public function accessCandidates(StatusPage $page): array
    {
        return $this->keys->candidates(KeyPurpose::SubscriberConsent, $page->id.':'.$page->password_hash, $page->password_hash ?? '');
    }

    /**
     * @param StatusPage $page Live page and password version.
     * @param string $stored Saved consent proof, including original-format values during upgrade.
     * @return bool Whether saved consent still matches the live password, without renewing stale consent.
     */
    public function allowsConsent(StatusPage $page, string $stored): bool
    {
        return $this->keys->matches($stored, KeyPurpose::SubscriberConsent, $page->id.':'.$page->password_hash, $page->password_hash ?? '');
    }

    /**
     * @param StatusPageSubscriber $subscriber Current recipient generation, never a previous revoked generation.
     * @return list<string> Non-expiring unsubscribe paths signed by each configured current/historical URL key.
     */
    public function retirementPaths(StatusPageSubscriber $subscriber): array
    {
        $paths = [];
        foreach ($this->keys->ring(KeyPurpose::SignedUrls) as $key) {
            $url = clone app(UrlGenerator::class);
            $url->setKeyResolver(static fn (): string => $key);
            $paths[] = $url->signedRoute('status-subscriptions.action', [
                'teamId' => $subscriber->team_id, 'pageId' => $subscriber->status_page_id,
                'subscriberId' => $subscriber->id, 'generation' => $subscriber->generation,
                'action' => 'unsubscribe',
            ], absolute: false);
        }

        return array_values(array_unique($paths));
    }
}
