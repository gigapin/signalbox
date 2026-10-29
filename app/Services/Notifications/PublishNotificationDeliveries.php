<?php

declare(strict_types=1);

/** Publish committed notification intents and recover lost broker publications with bounded, tenant-scoped work. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationWorkPublisher;
use App\Enums\Notifications\DeliveryStatus;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\Scopes\TeamScope;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Duplicate queue envelopes are harmless; the delivery ledger alone authorizes each provider attempt. */
final readonly class PublishNotificationDeliveries
{
    /** @var int Maximum identities loaded in one query; content is loaded only within its tenant. */
    private const int CHUNK_SIZE = 100;

    /**
     * @param TenantContext $tenants Scope every private lookup after global identity discovery.
     * @param NotificationWorkPublisher $publisher Replaceable Redis publication boundary.
     */
    public function __construct(private TenantContext $tenants, private NotificationWorkPublisher $publisher)
    {
    }

    /**
     * @param IncidentOpened|IncidentReopened|IncidentResolved $event Public event dispatched only after the transition commits.
     * Broker errors are isolated; the committed outbox remains discoverable by the recovery command.
     */
    public function publish(IncidentOpened|IncidentReopened|IncidentResolved $event): void
    {
        $this->tenants->run($event->teamId, function () use ($event): void {
            if (!Incident::query()->whereKey($event->incidentId)->where('monitor_id', $event->monitorId)->exists()) {
                return;
            }

            NotificationLog::query()->where('incident_id', $event->incidentId)->where('status', DeliveryStatus::Pending)
                ->whereHas('channel', fn (Builder $query): Builder => $query->where('team_id', $event->teamId))
                ->whereNotNull('message_uuid')
                ->chunkById(self::CHUNK_SIZE, function ($rows): void {
                    foreach ($rows as $row) {
                        $this->one($row->team_id, $row->id);
                    }
                });
        });
    }

    /** @return array{published: int, failed: int} Recoverable publications and individually isolated errors. */
    public function run(): array
    {
        $result = ['published' => 0, 'failed' => 0];
        $now = CarbonImmutable::now('UTC');

        // The only global query selects identities and eligibility, never recipient content or configuration.
        NotificationLog::withoutGlobalScope(TeamScope::class)->select(['id', 'team_id'])
            ->where('status', DeliveryStatus::Pending)->whereNotNull('message_uuid')
            ->where('available_at', '<=', $now)
            ->where(fn (Builder $query): Builder => $query->whereNull('claim_until')->orWhere('claim_until', '<=', $now))
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$result): void {
                foreach ($rows as $row) {
                    $outcome = $this->one($row->team_id, $row->id);

                    if ($outcome !== null) {
                        $result[$outcome ? 'published' : 'failed']++;
                    }
                }
            });

        return $result;
    }

    /**
     * @param int $teamId Discovered committed owner.
     * @param int $deliveryId Delivery to recheck inside that owner.
     * @return bool|null Publication outcome, or null for a concurrently completed/deleted/leased row.
     */
    private function one(int $teamId, int $deliveryId): ?bool
    {
        try {
            return $this->tenants->run($teamId, function () use ($teamId, $deliveryId): ?bool {
                $row = NotificationLog::query()->find($deliveryId);

                if ($row === null || $row->status !== DeliveryStatus::Pending || $row->message_uuid === null || $row->available_at === null
                    || ($row->claim_until !== null && $row->claim_until->isAfter(CarbonImmutable::now('UTC')))) {
                    return null;
                }

                $this->publisher->publish($teamId, $deliveryId, $row->available_at);

                return true;
            });
        } catch (Throwable) {
            Log::warning('Notification publication unconfirmed; the outbox will recover it.', ['team_id' => $teamId, 'delivery_id' => $deliveryId]);

            return false;
        }
    }
}
