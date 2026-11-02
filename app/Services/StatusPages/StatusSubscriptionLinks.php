<?php

declare(strict_types=1);

/** Create signed, revocable mailbox capabilities using the configured public origin. */

namespace App\Services\StatusPages;

use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Illuminate\Support\Facades\URL;

/** Relative signatures avoid dependence on request hosts; outgoing links always use APP_URL. */
final class StatusSubscriptionLinks
{
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
        return hash_hmac('sha256', $page->password_hash ?? '', (string) config('app.key'));
    }
}
