<?php

declare(strict_types=1);

/** Apply mailbox consent under the same Team lock used by page edits and incident publication. */

namespace App\Services\StatusPages;

use App\Enums\Notifications\DeliveryStatus;
use App\Models\StatusPage;
use App\Models\StatusPageDelivery;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Neither subscription state nor email existence is returned to an anonymous requester. */
final readonly class StatusSubscriptions
{
    /**
     * @param TenantContext $tenants Explicit tenant boundary after public page resolution.
     * @param StatusPageUnlocks $unlocks Live session authorization for protected pages.
     * @param StatusSubscriptionLinks $links Revocable mailbox capabilities and password binding.
     */
    public function __construct(
        private TenantContext $tenants,
        private StatusPageUnlocks $unlocks,
        private StatusSubscriptionLinks $links,
    ) {
    }

    /**
     * @param StatusPage $page Page discovered through the public lookup; reloaded under locks.
     * @param Session $session Visitor session proving the current password when required.
     * @param string $email Validated mailbox, canonicalized case-insensitively.
     */
    public function subscribe(StatusPage $page, Session $session, #[\SensitiveParameter] string $email): void
    {
        $this->tenants->run($page->team_id, fn () => DB::transaction(function () use ($page, $session, $email): void {
            Team::query()->lockForUpdate()->findOrFail($page->team_id);
            $current = StatusPage::query()->lockForUpdate()->findOrFail($page->id);
            abort_unless($current->is_public && $current->slug === $page->slug, 404);
            abort_unless($this->unlocks->allows($session, $current), 403);

            $address = mb_strtolower(trim($email));
            $hash = hash_hmac('sha256', $address, (string) config('app.key'));
            $subscriber = StatusPageSubscriber::query()
                ->where('status_page_id', $current->id)
                ->where('email_hash', $hash)
                ->lockForUpdate()
                ->first();
            $now = CarbonImmutable::now('UTC');
            $access = $this->links->accessHash($current);

            if ($subscriber !== null && $subscriber->unsubscribed_at === null && hash_equals($subscriber->access_hash, $access)) {
                if ($subscriber->verified_at !== null || $subscriber->requested_at->addMinutes(10)->isAfter($now)) {
                    return;
                }
            }

            $subscriber ??= new StatusPageSubscriber();
            $subscriber->forceFill([
                'status_page_id' => $current->id,
                'email' => $address,
                'email_hash' => $hash,
                'generation' => (string) Str::uuid(),
                'access_hash' => $access,
                'requested_at' => $now,
                'verification_expires_at' => $now->addHour(),
                'verified_at' => null,
                'unsubscribed_at' => null,
            ])->save();

            // The generic verification email discloses no protected title or incident text before opt-in.
            $this->enqueue($subscriber, 'verification', null, [
                'subject' => 'Confirm status page updates',
                'text' => "Confirm your request to receive status page updates within one hour:\n".$this->links->action($subscriber, 'verify')
                    ."\n\nIf you did not request this, ignore this email or cancel the request:\n".$this->links->action($subscriber, 'unsubscribe'),
            ]);
        }));
    }

    /**
     * Signature validation belongs to the route middleware and runs before this explicit tenant lookup.
     * GET only validates; POST commits. Unsubscribing works even after a page becomes a draft or changes password.
     *
     * @param int $teamId Signed tenant identity.
     * @param int $pageId Signed page identity.
     * @param int $subscriberId Signed recipient identity.
     * @param string $generation Revocable signed consent generation.
     * @param string $action Closed route choice: verify or unsubscribe.
     * @param bool $commit Whether the visitor explicitly submitted the form.
     */
    public function act(int $teamId, int $pageId, int $subscriberId, string $generation, string $action, bool $commit): void
    {
        $this->tenants->run($teamId, fn () => DB::transaction(function () use ($teamId, $pageId, $subscriberId, $generation, $action, $commit): void {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $page = StatusPage::query()->lockForUpdate()->findOrFail($pageId);
            $subscriber = StatusPageSubscriber::query()->where('status_page_id', $pageId)->lockForUpdate()->findOrFail($subscriberId);
            abort_unless(hash_equals($subscriber->generation, $generation), 404);
            $now = CarbonImmutable::now('UTC');

            if ($action === 'verify') {
                abort_unless($page->is_public && $subscriber->unsubscribed_at === null
                    && $subscriber->verification_expires_at->isAfter($now)
                    && hash_equals($subscriber->access_hash, $this->links->accessHash($page)), 404);

                if ($commit && $subscriber->verified_at === null) {
                    $subscriber->forceFill(['verified_at' => $now])->save();
                }
            } elseif ($commit && $subscriber->unsubscribed_at === null) {
                $subscriber->forceFill(['unsubscribed_at' => $now])->save();
                StatusPageDelivery::query()->where('subscriber_id', $subscriber->id)->where('status', DeliveryStatus::Pending)
                    ->whereNull('claim_until')->update(['status' => DeliveryStatus::Suppressed->value]);
            }
        }));
    }

    /**
     * Caller holds the Team lock and transaction; one intent is frozen per recipient/generation/publication.
     *
     * @param StatusPageSubscriber $subscriber Current verified recipient, or pending recipient for verification.
     * @param string $kind Verification or update.
     * @param int|null $updateId Explicitly published timeline entry.
     * @param array{subject: string, text: string} $payload Recipient-only plain text.
     */
    public function enqueue(StatusPageSubscriber $subscriber, string $kind, ?int $updateId, #[\SensitiveParameter] array $payload): void
    {
        $key = $subscriber->generation.':'.$kind.':'.($updateId ?? 'consent');

        if (StatusPageDelivery::query()->where('subscriber_id', $subscriber->id)->where('dedupe_key', $key)->exists()) {
            return;
        }

        (new StatusPageDelivery())->forceFill([
            'status_page_id' => $subscriber->status_page_id,
            'subscriber_id' => $subscriber->id,
            'incident_update_id' => $updateId,
            'generation' => $subscriber->generation,
            'message_uuid' => (string) Str::uuid(),
            'dedupe_key' => $key,
            'kind' => $kind,
            'payload' => $payload,
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
            'available_at' => CarbonImmutable::now('UTC'),
        ])->save();
    }
}
