<?php

declare(strict_types=1);

/** Prepare durable, deduplicated recipient deliveries while an incident transition is still transactional. */

namespace App\Services\Notifications;

use App\Enums\Notifications\DeliveryStatus;
use App\Enums\Notifications\NotificationKind;
use App\Events\Incidents\IncidentNotificationRequested;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** This synchronous event listener performs database work only; public after-commit events publish scalar jobs later. */
final readonly class PrepareIncidentNotifications
{
    /**
     * @param TenantContext $tenants Explicit scope even when invoked outside the original lifecycle context.
     * @param EscalationPolicyResolver $policies Monitor override, then default, then no notification.
     * @param IncidentNotificationMessage $messages Explicit recipient snapshots without monitor network secrets.
     * @param ChannelDestinationFingerprint $destinations Prevent delivery to an edited/reverified destination.
     * @param NotificationDeliverySettings $settings Coalescing window and bounded delivery policy.
     */
    public function __construct(
        private TenantContext $tenants,
        private EscalationPolicyResolver $policies,
        private IncidentNotificationMessage $messages,
        private ChannelDestinationFingerprint $destinations,
        private NotificationDeliverySettings $settings,
    ) {
    }

    /**
     * @param IncidentNotificationRequested $event Trusted scalar transition request from the incident lifecycle.
     * Missing, foreign or stale identities have no effect; duplicate events keep the original UUID and content.
     */
    public function record(IncidentNotificationRequested $event): void
    {
        $this->tenants->run($event->teamId, function () use ($event): void {
            DB::transaction(function () use ($event): void {
                if (Team::query()->whereKey($event->teamId)->lockForUpdate()->first() === null) {
                    return;
                }

                $monitor = Monitor::query()->whereKey($event->monitorId)->lockForUpdate()->first();
                $incident = Incident::query()->whereKey($event->incidentId)->where('monitor_id', $event->monitorId)->lockForUpdate()->first();

                if ($monitor === null || $incident === null || $incident->reopened_count !== $event->cycle
                    || !in_array($event->event, ['incident.opened', 'incident.reopened', 'incident.resolved'], true)
                    || ($event->event === 'incident.resolved') !== ($incident->resolved_at !== null)
                    || ($event->event === 'incident.opened' && $event->cycle !== 0)
                    || ($event->event === 'incident.reopened' && $event->cycle === 0)) {
                    return;
                }

                $now = CarbonImmutable::now('UTC');
                $kind = $event->event === 'incident.resolved' ? NotificationKind::Resolved : NotificationKind::Opened;

                // Freeze even an empty audience: replaying an event must not adopt a subsequently edited policy.
                if (DB::table('incident_notification_events')->where('team_id', $event->teamId)
                    ->where('incident_id', $incident->id)->where('cycle', $event->cycle)->where('kind', $kind->value)->exists()) {
                    return;
                }

                DB::table('incident_notification_events')->insert([
                    'team_id' => $event->teamId,
                    'incident_id' => $incident->id,
                    'cycle' => $event->cycle,
                    'kind' => $kind->value,
                    'created_at' => $now,
                ]);

                if ($event->event === 'incident.resolved') {
                    $this->resolve($incident, $monitor, $event, $now);

                    return;
                }

                $this->cancelWaiting($incident, $now, true);
                $policy = $this->policies->forMonitor($monitor);
                $confirmedAt = $incident->last_reopened_at ?? $incident->confirmed_at;

                foreach ($policy?->steps() ?? [] as $index => $step) {
                    $scheduledAt = $confirmedAt->addMinutes($step->afterMinutes);
                    $availableAt = $scheduledAt->max($now->addSeconds($this->settings->coalesceSeconds));
                    $kind = $index === 0 ? NotificationKind::Opened : NotificationKind::Escalated;
                    $name = $index === 0 ? $event->event : 'incident.escalated';

                    foreach (NotificationChannel::query()->whereIn('id', $step->channelIds)->get() as $channel) {
                        $this->prepare($incident, $monitor, $channel, $kind, $name, $availableAt, stepIndex: $index, scheduledAt: $scheduledAt);
                    }
                }
            });
        });
    }

    /**
     * @param Incident $incident Locked incident in its new state.
     * @param CarbonImmutable $now Current UTC time.
     * @param bool $reopening Whether older-cycle openings/resolutions should be coalesced.
     */
    private function cancelWaiting(Incident $incident, CarbonImmutable $now, bool $reopening): void
    {
        $query = NotificationLog::query()
            ->where('incident_id', $incident->id)
            ->where('status', DeliveryStatus::Pending)
            ->where(fn (Builder $query): Builder => $query->whereNull('claim_until')->orWhere('claim_until', '<=', $now));

        if ($reopening) {
            $query->where('cycle', '<', $incident->reopened_count);
        } else {
            $query->where('kind', '!=', NotificationKind::Resolved->value);
        }

        $query->update([
            'status' => DeliveryStatus::Suppressed->value,
            'claim_until' => null,
            'response' => 'Coalesced with a newer incident transition before provider acceptance.',
        ]);
    }

    /**
     * @param Incident $incident Locked resolved incident.
     * @param Monitor $monitor Locked monitor.
     * @param IncidentNotificationRequested $event Current resolution request.
     * @param CarbonImmutable $now Current UTC time.
     */
    private function resolve(Incident $incident, Monitor $monitor, IncidentNotificationRequested $event, CarbonImmutable $now): void
    {
        $this->cancelWaiting($incident, $now, false);
        $recipients = NotificationLog::query()
            ->where('incident_id', $incident->id)
            ->whereNotNull('channel_id')
            ->where('kind', '!=', NotificationKind::Resolved->value)
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $sent): void {
                    $sent->where('status', DeliveryStatus::Sent)
                        ->whereNotExists(function (QueryBuilder $resolved): void {
                            $resolved->selectRaw('1')->from('notification_logs as summaries')
                                ->whereColumn('summaries.team_id', 'notification_logs.team_id')
                                ->whereColumn('summaries.incident_id', 'notification_logs.incident_id')
                                ->whereColumn('summaries.channel_id', 'notification_logs.channel_id')
                                ->whereColumn('summaries.cycle', '>=', 'notification_logs.cycle')
                                ->where('summaries.kind', NotificationKind::Resolved->value)
                                ->where('summaries.status', DeliveryStatus::Sent->value);
                        });
                })->orWhere(fn (Builder $pending): Builder => $pending->where('status', DeliveryStatus::Pending)->where('claim_until', '>', $now));
            })
            ->orderByDesc('id')
            ->get()
            ->unique('channel_id');

        foreach ($recipients as $opening) {
            $channel = $opening->channel;

            if ($channel !== null && $opening->destination_hash !== null) {
                $this->prepare($incident, $monitor, $channel, NotificationKind::Resolved, $event->event, $now->addSeconds($this->settings->coalesceSeconds), $opening->destination_hash);
            }
        }
    }

    /**
     * @param Incident $incident Locked incident after its transition.
     * @param Monitor $monitor Locked monitor providing its public label only.
     * @param NotificationChannel $channel Recipient inside the same tenant.
     * @param NotificationKind $kind Opening, later escalation or resolution summary.
     * @param string $event Controlled event name for the snapshot.
     * @param CarbonImmutable $availableAt Earliest provider attempt time.
     * @param string|null $originalDestination Original notified destination for a resolution summary.
     * @param int|null $stepIndex Zero-based frozen policy step; null for a resolution.
     * @param CarbonImmutable|null $scheduledAt Absolute step instant; escalation event time independent of queue latency.
     */
    private function prepare(
        Incident $incident,
        Monitor $monitor,
        NotificationChannel $channel,
        NotificationKind $kind,
        string $event,
        CarbonImmutable $availableAt,
        ?string $originalDestination = null,
        ?int $stepIndex = null,
        ?CarbonImmutable $scheduledAt = null,
    ): void {
        $key = 'incident:'.$incident->id.':cycle:'.$incident->reopened_count.':'.$kind->value.':channel:'.$channel->id;

        if ($kind === NotificationKind::Escalated) {
            $key .= ':step:'.$stepIndex;
        }

        if (NotificationLog::query()->where('dedupe_key', $key)->exists()) {
            return;
        }

        $message = $this->messages->make($incident, $monitor, $event, $stepIndex, $scheduledAt);
        $active = $channel->is_active && $channel->verified_at !== null;
        $log = new NotificationLog();
        $log->forceFill([
            'incident_id' => $incident->id,
            'channel_id' => $channel->id,
            'channel_type' => $channel->type,
            'kind' => $kind,
            'step_index' => $stepIndex,
            'status' => $active ? DeliveryStatus::Pending : DeliveryStatus::Suppressed,
            'attempts' => 0,
            'response' => $active ? null : 'The channel is inactive or unverified.',
            'dedupe_key' => $key,
            'cycle' => $incident->reopened_count,
            'message_uuid' => $message->id,
            'message_payload' => $message->payload(),
            'destination_hash' => $originalDestination ?? $this->destinations->for($channel->configuration()),
            'available_at' => $availableAt,
        ])->save();
    }
}
