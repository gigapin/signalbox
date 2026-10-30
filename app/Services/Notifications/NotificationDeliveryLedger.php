<?php

declare(strict_types=1);

/** Admit bounded delivery attempts and fence completion/retry transitions without holding locks across provider I/O. */

namespace App\Services\Notifications;

use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\NotificationAttempt;
use App\Enums\Notifications\DeliveryStatus;
use App\Enums\Notifications\NotificationKind;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Services\Incidents\MaintenanceSchedule;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** Every mutation follows Team → Monitor → Incident → delivery, coordinating lifecycle and recipient changes. */
final readonly class NotificationDeliveryLedger
{
    /**
     * @param TenantContext $tenants Explicit team boundary restored on every exit path.
     * @param NotificationDeliverySettings $settings Persistent attempt budget and retry delays.
     * @param ChannelDestinationFingerprint $destinations Bind attempts to the verified destination selected originally.
     * @param MaintenanceSchedule $maintenance Recheck the monitor's half-open UTC windows before every provider attempt.
     */
    public function __construct(
        private TenantContext $tenants,
        private NotificationDeliverySettings $settings,
        private ChannelDestinationFingerprint $destinations,
        private MaintenanceSchedule $maintenance,
    ) {
    }

    /**
     * @param int $teamId Previously committed tenant identity from a scalar queue envelope.
     * @param int $deliveryId Delivery of that tenant.
     * @param string $token Unique ownership fence of this queue envelope.
     * @return NotificationAttempt|null Authorized attempt, or null for a duplicate, delayed, stale or suppressed row.
     * @throws InvalidArgumentException When the envelope fence is not a UUID.
     */
    public function acquire(int $teamId, int $deliveryId, string $token): ?NotificationAttempt
    {
        if (!Str::isUuid($token)) {
            throw new InvalidArgumentException('A notification attempt requires a UUID fence.');
        }

        return $this->locked($teamId, $deliveryId, function (NotificationLog $log, Incident $incident) use ($token): ?NotificationAttempt {
            $now = CarbonImmutable::now('UTC');

            if ($log->status !== DeliveryStatus::Pending || $log->message_uuid === null || $log->claim_token === $token
                || $log->available_at === null || $log->available_at->isAfter($now)
                || ($log->claim_until !== null && $log->claim_until->isAfter($now))) {
                return null;
            }

            $resolved = $log->kind === NotificationKind::Resolved;

            if ($log->cycle !== $incident->reopened_count || $resolved !== ($incident->resolved_at !== null)) {
                $this->suppress($log, 'Superseded by the current incident state.');

                return null;
            }

            if ($this->maintenance->activeAt($incident->monitor_id, $now)) {
                $this->suppress($log, 'The monitor is under planned maintenance.');

                return null;
            }

            $channel = $log->channel;

            if ($channel === null || !$channel->is_active || $channel->verified_at === null) {
                $this->suppress($log, 'The channel was deleted, paused or is no longer verified.');

                return null;
            }

            try {
                $quietHours = $channel->quietHours();
                $configuration = $channel->configuration();
                $message = $log->message();
            } catch (Throwable) {
                $log->forceFill(['status' => DeliveryStatus::Failed, 'claim_until' => null, 'response' => 'The stored recipient configuration or message could not be read safely.'])->save();

                return null;
            }

            if ($quietHours?->contains($now) === true) {
                $this->suppress($log, 'The channel is within its configured quiet hours.');

                return null;
            }

            if ($log->claim_until !== null) {
                $this->retry($log, 'The previous worker did not complete its delivery attempt.', $now);

                return null;
            }

            if ($channel->type !== $log->channel_type
                || !hash_equals($log->destination_hash ?? '', $this->destinations->for($configuration))) {
                $this->suppress($log, 'The verified destination changed after this delivery was prepared.');

                return null;
            }

            // Serialize provider attempts for one incident/recipient even across rapid lifecycle changes.
            if (NotificationLog::query()->where('incident_id', $incident->id)->where('channel_id', $channel->id)
                ->where('id', '!=', $log->id)->where('status', DeliveryStatus::Pending)->where('claim_until', '>', $now)->exists()) {
                return null;
            }

            if ($resolved && !NotificationLog::query()->where('incident_id', $incident->id)->where('channel_id', $channel->id)
                ->where('kind', '!=', NotificationKind::Resolved->value)->where('status', DeliveryStatus::Sent)
                ->where('destination_hash', $log->destination_hash)->exists()) {
                $this->suppress($log, 'No opening or escalation was accepted for this recipient.');

                return null;
            }

            if ($log->attempts >= $this->settings->maxAttempts) {
                $log->forceFill(['status' => DeliveryStatus::Failed, 'response' => 'The delivery attempt budget is exhausted.'])->save();

                return null;
            }

            $log->forceFill([
                'attempts' => $log->attempts + 1,
                'claim_token' => $token,
                'claim_until' => $now->addSeconds(NotificationDeliverySettings::LEASE_SECONDS),
            ])->save();

            return new NotificationAttempt($log->team_id, $log->id, $token, $configuration, $message);
        });
    }

    /**
     * @param int $teamId Owning tenant.
     * @param int $deliveryId Claimed row.
     * @param string $token Attempt fence; stale acknowledgements have no effect.
     * @param ChannelDeliveryReceipt $receipt Provider acceptance or explicit SMS suppression.
     * @return bool Whether this attempt still owned and completed the row.
     */
    public function complete(int $teamId, int $deliveryId, string $token, ChannelDeliveryReceipt $receipt): bool
    {
        return $this->locked($teamId, $deliveryId, function (NotificationLog $log) use ($token, $receipt): bool {
            if (!$this->owns($log, $token)) {
                return false;
            }

            $log->forceFill([
                'status' => $receipt->status(),
                'response' => $receipt->summary(),
                'claim_until' => null,
                'sent_at' => $receipt->stubbed ? null : CarbonImmutable::now('UTC'),
            ])->save();

            return true;
        }) ?? false;
    }

    /**
     * @param int $teamId Owning tenant.
     * @param int $deliveryId Claimed row.
     * @param string $token Attempt fence, including when restored by the worker failed hook.
     * @param string $safeReason Redacted diagnostic only; raw provider exceptions must never enter this method.
     * @return bool Whether this failure still owned and advanced the delivery.
     */
    public function fail(int $teamId, int $deliveryId, string $token, string $safeReason): bool
    {
        return $this->locked($teamId, $deliveryId, function (NotificationLog $log) use ($token, $safeReason): bool {
            if (!$this->owns($log, $token)) {
                return false;
            }

            $this->retry($log, $safeReason, CarbonImmutable::now('UTC'));

            return true;
        }) ?? false;
    }

    /**
     * @param NotificationLog $log Locked pending row.
     * @param string $token Expected attempt ownership.
     * @return bool Whether a live lease and its fence still belong to the caller.
     */
    private function owns(NotificationLog $log, string $token): bool
    {
        return $log->status === DeliveryStatus::Pending && $log->claim_token === $token
            && $log->claim_until !== null && $log->claim_until->isAfter(CarbonImmutable::now('UTC'));
    }

    /**
     * @param NotificationLog $log Locked acquired or expired row, whose attempt was already counted.
     * @param string $safeReason Redacted, bounded diagnostic.
     * @param CarbonImmutable $now Current UTC time.
     */
    private function retry(NotificationLog $log, string $safeReason, CarbonImmutable $now): void
    {
        $log->forceFill([
            'status' => $log->attempts >= $this->settings->maxAttempts ? DeliveryStatus::Failed : DeliveryStatus::Pending,
            'response' => mb_substr($safeReason, 0, 1000),
            'claim_until' => null,
            'available_at' => $now->addSeconds($this->settings->retryDelay($log->attempts)),
        ])->save();
    }

    /**
     * @param NotificationLog $log Locked row no longer eligible for sending.
     * @param string $reason Fixed safe suppression reason.
     */
    private function suppress(NotificationLog $log, string $reason): void
    {
        $log->forceFill(['status' => DeliveryStatus::Suppressed, 'claim_until' => null, 'response' => $reason])->save();
    }

    /**
     * @template TResult
     * @param int $teamId Explicit owner from the queue or lifecycle event.
     * @param int $deliveryId Delivery to load only inside that team.
     * @param Closure(NotificationLog, Incident): TResult $operation Work under the common domain lock order.
     * @return TResult|null Work result, or null when an identity has been deleted or belongs elsewhere.
     */
    private function locked(int $teamId, int $deliveryId, Closure $operation): mixed
    {
        return $this->tenants->run($teamId, function () use ($teamId, $deliveryId, $operation): mixed {
            return DB::transaction(function () use ($teamId, $deliveryId, $operation): mixed {
                if (Team::query()->whereKey($teamId)->lockForUpdate()->first() === null) {
                    return null;
                }

                $log = NotificationLog::query()->find($deliveryId);
                $incident = $log === null ? null : Incident::query()->find($log->incident_id);

                if ($incident === null || Monitor::query()->whereKey($incident->monitor_id)->lockForUpdate()->first() === null) {
                    return null;
                }

                $incident = Incident::query()->whereKey($incident->id)->lockForUpdate()->first();
                $log = NotificationLog::query()->whereKey($deliveryId)->lockForUpdate()->first();

                return $incident === null || $log === null ? null : $operation($log, $incident);
            });
        });
    }
}
