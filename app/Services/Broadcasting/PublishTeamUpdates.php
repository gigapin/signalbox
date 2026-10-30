<?php

declare(strict_types=1);

/** Queue committed private UI updates without coupling domain success to Redis or Reverb availability. */

namespace App\Services\Broadcasting;

use App\Events\Broadcasting\TeamUpdate;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Events\Monitoring\CheckCompleted;
use App\Models\CheckResult;
use App\Models\Incident;
use App\Models\Monitor;
use App\Services\Tenancy\TenantContext;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Explicit publish registration avoids convention-discovered duplicate listeners; no network request runs in this listener. */
final readonly class PublishTeamUpdates
{
    /**
     * @param TenantContext $tenants Restore the surrounding request/worker identity after every correlation check.
     * @param BroadcastManager $broadcasts Native queued broadcasting; TeamUpdate pins Redis, queue and bounded transport retries.
     * @param Repository $config The null broadcaster disables publication without touching Redis.
     */
    public function __construct(private TenantContext $tenants, private BroadcastManager $broadcasts, private Repository $config)
    {
    }

    /**
     * @param CheckCompleted|IncidentOpened|IncidentReopened|IncidentResolved $event Domain event dispatched only after its transaction commits.
     * Missing/foreign correlations have no effect. Broker failures are logged safely and never undo committed domain work.
     */
    public function publish(CheckCompleted|IncidentOpened|IncidentReopened|IncidentResolved $event): void
    {
        if (in_array($this->config->get('broadcasting.default'), [null, 'null'], true)) {
            return;
        }

        try {
            $this->tenants->run($event->teamId, function () use ($event): void {
                if (!Monitor::query()->whereKey($event->monitorId)->exists()) {
                    return;
                }

                if ($event instanceof CheckCompleted) {
                    $valid = CheckResult::query()->where('execution_id', $event->executionId)
                        ->where('monitor_id', $event->monitorId)->where('slot_at', $event->slotAt->utc())
                        ->where('monitor_revision', $event->monitorRevision)->where('region', $event->region->value)->exists();
                } else {
                    $valid = Incident::query()->whereKey($event->incidentId)->where('monitor_id', $event->monitorId)->exists();
                }

                if ($valid) {
                    $this->broadcasts->queue(new TeamUpdate($event));
                }
            });
        } catch (Throwable) {
            Log::warning('Team update publication failed; committed domain data remains authoritative.', [
                'team_id' => $event->teamId,
                'monitor_id' => $event->monitorId,
            ]);
        }
    }
}
