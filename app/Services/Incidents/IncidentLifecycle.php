<?php

declare(strict_types=1);

/** Apply incident state changes together with their timeline entry and after-commit event. */

namespace App\Services\Incidents;

use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\CheckError;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Callers hold the Team → Monitor → incident locks inside one transaction. Instants are clamped so a regressing
 * wall clock cannot violate the temporal constraints and roll back the result that triggered the change.
 */
final readonly class IncidentLifecycle
{
    /** @param Dispatcher $events Event dispatcher honoring after-commit events. */
    public function __construct(private Dispatcher $events)
    {
    }

    /**
     * @param Monitor $monitor Locked monitor without an unresolved incident.
     * @param CarbonImmutable $startedAt First failing slot of the confirmed sequence.
     * @param CheckError $cause Cause of the most severe confirming region.
     * @param CarbonImmutable $now UTC confirmation instant.
     * @return Incident New investigating incident with its opening entry.
     */
    public function open(Monitor $monitor, CarbonImmutable $startedAt, CheckError $cause, CarbonImmutable $now): Incident
    {
        $incident = new Incident();
        $incident->forceFill([
            'monitor_id' => $monitor->id,
            'status' => IncidentStatus::Investigating,
            'severity' => IncidentSeverity::forCause($cause),
            'cause' => $cause,
            'started_at' => $startedAt,
            'confirmed_at' => $now->max($startedAt),
        ])->save();

        $message = sprintf(
            'Failure confirmed after %d consecutive failed checks in every configured region (%s).',
            $monitor->confirmation_threshold,
            $cause->value,
        );
        $this->record($incident, null, $message, $now);
        $this->events->dispatch(new IncidentOpened($incident->team_id, $incident->id, $incident->monitor_id));

        return $incident;
    }

    /**
     * @param Incident $incident Locked resolved incident inside the reopen window.
     * @param CarbonImmutable $now UTC confirmation instant of the new failure.
     */
    public function reopen(Incident $incident, CarbonImmutable $now): void
    {
        $incident->forceFill([
            'status' => IncidentStatus::Investigating,
            'resolved_at' => null,
            'duration_seconds' => null,
            'reopened_count' => $incident->reopened_count + 1,
            'last_reopened_at' => $now->max($incident->confirmed_at),
        ])->save();

        $this->record($incident, null, 'The failure was confirmed again shortly after resolution; the incident was reopened.', $now);
        $this->events->dispatch(new IncidentReopened($incident->team_id, $incident->id, $incident->monitor_id));
    }

    /**
     * @param Incident $incident Locked open incident.
     * @param User|null $author Person resolving the incident; null for automatic recovery.
     * @param string $message Validated timeline message.
     * @param CarbonImmutable $now UTC resolution instant.
     * @return IncidentUpdate Timeline entry recording the resolution.
     */
    public function resolve(Incident $incident, ?User $author, string $message, CarbonImmutable $now): IncidentUpdate
    {
        $resolvedAt = $now->max($incident->confirmed_at);

        if ($incident->last_reopened_at !== null) {
            $resolvedAt = $resolvedAt->max($incident->last_reopened_at);
        }

        $incident->forceFill([
            'status' => IncidentStatus::Resolved,
            'resolved_at' => $resolvedAt,
            'duration_seconds' => (int) $incident->started_at->diffInSeconds($resolvedAt),
        ])->save();

        $update = $this->record($incident, $author, $message, $now);
        $this->events->dispatch(new IncidentResolved($incident->team_id, $incident->id, $incident->monitor_id));

        return $update;
    }

    /**
     * @param Incident $incident Incident in its status after the change being recorded.
     * @param User|null $author Writing person; null for system entries.
     * @param string $message Validated nonblank message.
     * @param CarbonImmutable $now UTC entry instant.
     * @return IncidentUpdate Private timeline entry.
     */
    public function record(Incident $incident, ?User $author, string $message, CarbonImmutable $now): IncidentUpdate
    {
        $update = new IncidentUpdate();
        $update->forceFill([
            'incident_id' => $incident->id,
            'author_id' => $author?->id,
            'status' => $incident->status,
            'message' => $message,
            'created_at' => $now,
        ])->save();

        return $update;
    }
}
