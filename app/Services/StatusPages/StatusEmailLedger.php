<?php

declare(strict_types=1);

/** Admit, fence and finish public email attempts under tenant and consent locks. */

namespace App\Services\StatusPages;

use App\Data\StatusPages\StatusEmailAttempt;
use App\Enums\Notifications\DeliveryStatus;
use App\Models\StatusPage;
use App\Models\StatusPageDelivery;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Services\Notifications\NotificationDeliverySettings;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Provider calls happen after commit. Consent changes stop future admission; an admitted SMTP call cannot be recalled. */
final readonly class StatusEmailLedger
{
    /**
     * @param TenantContext $tenants Restore the caller's tenant even on failures.
     * @param StatusSubscriptionLinks $links Compare current access with the recipient's consent.
     * @param NotificationDeliverySettings $settings Shared bounded retry and lease policy.
     */
    public function __construct(
        private TenantContext $tenants,
        private StatusSubscriptionLinks $links,
        private NotificationDeliverySettings $settings,
    ) {
    }

    /**
     * @param int $teamId Scalar committed owner.
     * @param int $id Delivery identity.
     * @param string $token Unique queue envelope fence.
     * @return StatusEmailAttempt|null Decrypted content only after current consent and visibility checks.
     */
    public function acquire(int $teamId, int $id, string $token): ?StatusEmailAttempt
    {
        return $this->locked($teamId, $id, function (StatusPage $page, StatusPageSubscriber $subscriber, StatusPageDelivery $row) use ($token): ?StatusEmailAttempt {
            $now = CarbonImmutable::now('UTC');

            if ($row->status !== DeliveryStatus::Pending || $row->available_at->isAfter($now)
                || ($row->claim_until !== null && $row->claim_until->isAfter($now))) {
                return null;
            }

            if ($row->claim_until !== null) {
                $this->retry($row, $now);

                return null;
            }

            $allowed = $page->is_public && $subscriber->unsubscribed_at === null
                && hash_equals($row->generation, $subscriber->generation)
                && hash_equals($subscriber->access_hash, $this->links->accessHash($page));

            if ($row->kind === 'verification') {
                $allowed = $allowed && $subscriber->verified_at === null && $subscriber->verification_expires_at->isAfter($now);
            } else {
                $allowed = $allowed && $subscriber->verified_at !== null && $this->stillSelected($row);
            }

            if (!$allowed) {
                $row->forceFill(['status' => DeliveryStatus::Suppressed, 'claim_until' => null])->save();

                return null;
            }

            try {
                $payload = $row->payload;
                $attempt = new StatusEmailAttempt($subscriber->email, $payload['subject'], $payload['text'], $row->message_uuid);
            } catch (Throwable) {
                // A corrupt encrypted payload is terminal; no raw exception or sensitive data reaches logs.
                $row->forceFill(['status' => DeliveryStatus::Failed, 'claim_until' => null])->save();

                return null;
            }

            $row->forceFill([
                'attempts' => $row->attempts + 1,
                'claim_token' => $token,
                'claim_until' => $now->addSeconds(NotificationDeliverySettings::LEASE_SECONDS),
            ])->save();

            return $attempt;
        });
    }

    /**
     * @param int $teamId Committed owner.
     * @param int $id Delivery identity.
     * @param string $token Immutable worker fence.
     * @param bool $sent Whether SMTP accepted the message; false schedules bounded retry.
     */
    public function finish(int $teamId, int $id, string $token, bool $sent): void
    {
        $this->locked($teamId, $id, function (StatusPage $page, StatusPageSubscriber $subscriber, StatusPageDelivery $row) use ($token, $sent): void {
            $now = CarbonImmutable::now('UTC');

            if ($row->status !== DeliveryStatus::Pending || $row->claim_token !== $token
                || $row->claim_until === null || !$row->claim_until->isAfter($now)) {
                return;
            }

            if ($sent) {
                $row->forceFill(['status' => DeliveryStatus::Sent, 'sent_at' => $now, 'claim_until' => null])->save();
            } else {
                $this->retry($row, $now);
            }
        });
    }

    /**
     * @param StatusPageDelivery $row Locked acquired or expired attempt.
     * @param CarbonImmutable $now Failure observation time; recovery starts backoff here.
     */
    private function retry(StatusPageDelivery $row, CarbonImmutable $now): void
    {
        $row->forceFill([
            'status' => $row->attempts >= $this->settings->maxAttempts ? DeliveryStatus::Failed : DeliveryStatus::Pending,
            'available_at' => $now->addSeconds($this->settings->retryDelay($row->attempts)),
            'claim_until' => null,
        ])->save();
    }

    /**
     * @param StatusPageDelivery $row Update email already scoped to its tenant.
     * @return bool Whether this published entry still belongs to a monitor selected on this page.
     */
    private function stillSelected(StatusPageDelivery $row): bool
    {
        return DB::table('incident_updates as entry')
            ->join('incidents as incident', 'incident.id', '=', 'entry.incident_id')
            ->join('status_page_component_monitors as selection', 'selection.monitor_id', '=', 'incident.monitor_id')
            ->where('entry.team_id', $row->team_id)->where('incident.team_id', $row->team_id)
            ->where('selection.team_id', $row->team_id)->where('selection.status_page_id', $row->status_page_id)
            ->where('entry.id', $row->incident_update_id)->where('entry.is_public', true)->exists();
    }

    /**
     * @template TResult
     * @param int $teamId Owner discovered from an identity-only scan or scalar job.
     * @param int $id Delivery identity.
     * @param Closure(StatusPage, StatusPageSubscriber, StatusPageDelivery): TResult $operation Operation under Team → Page → Subscriber → Delivery locks.
     * @return TResult|null Operation result, or null after deletion or for a foreign identity.
     */
    private function locked(int $teamId, int $id, Closure $operation): mixed
    {
        return $this->tenants->run($teamId, fn () => DB::transaction(function () use ($teamId, $id, $operation): mixed {
            if (Team::query()->lockForUpdate()->find($teamId) === null) {
                return null;
            }

            $identity = StatusPageDelivery::query()->find($id, ['id', 'status_page_id', 'subscriber_id']);

            if ($identity === null) {
                return null;
            }

            $page = StatusPage::query()->lockForUpdate()->findOrFail($identity->status_page_id);
            $subscriber = StatusPageSubscriber::query()->where('status_page_id', $page->id)->lockForUpdate()->findOrFail($identity->subscriber_id);
            $row = StatusPageDelivery::query()->lockForUpdate()->findOrFail($id);

            return $operation($page, $subscriber, $row);
        }));
    }
}
