<?php

declare(strict_types=1);

/** Apply manual incident changes: responsibility, timeline updates, severity, declared cause and publication. */

namespace App\Actions\Incidents;

use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Exceptions\IncidentChangeRejected;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Incidents\IncidentLifecycle;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Every change locks Team → Monitor → Incident, the same order as automatic detection, and re-runs the policy
 * under the locks. Reopening stays automatic: people cannot move a resolved incident back to an open status.
 */
final readonly class ManageIncidents
{
    /** @var int Maximum characters of a timeline message or declared cause, shared with future request validation. */
    public const int TEXT_MAX_LENGTH = 5000;

    /**
     * @param TenantContext $context Explicit tenant boundary for each change.
     * @param IncidentLifecycle $lifecycle Shared state changes, entries and events.
     */
    public function __construct(private TenantContext $context, private IncidentLifecycle $lifecycle)
    {
    }

    /**
     * Idempotent for the current responsible user; another manager takes over responsibility. A resolved
     * incident is rejected with IncidentChangeRejected.
     *
     * @param User $actor Manager taking responsibility.
     * @param int $teamId Active team.
     * @param int $incidentId Incident of that team.
     * @return Incident Acknowledged incident.
     */
    public function acknowledge(User $actor, int $teamId, int $incidentId): Incident
    {
        return $this->locked($teamId, $incidentId, $actor, function (Incident $incident, CarbonImmutable $now) use ($actor): Incident {
            if (!$incident->status->isOpen()) {
                throw new IncidentChangeRejected('A resolved incident cannot be acknowledged.');
            }

            if ($incident->acknowledged_by === $actor->id) {
                return $incident;
            }

            $message = $incident->acknowledged_by === null ? 'Responsibility taken.' : 'Responsibility reassigned.';
            $incident->forceFill(['acknowledged_by' => $actor->id, 'acknowledged_at' => $now])->save();
            $this->lifecycle->record($incident, $actor, $message, $now);

            return $incident;
        });
    }

    /**
     * Keep the status to add a note, move between open statuses, resolve an open incident, or add a note after
     * resolution. Resolving while the failure persists lets the next confirmation reopen the incident.
     *
     * @param User $actor Manager writing the entry.
     * @param int $teamId Active team.
     * @param int $incidentId Incident of that team.
     * @param IncidentStatus $status Status after the entry.
     * @param string $message Entry text; surrounding whitespace is removed.
     * @return IncidentUpdate New private entry.
     * @throws IncidentChangeRejected When the message is invalid or the status change is not admitted.
     */
    public function postUpdate(User $actor, int $teamId, int $incidentId, IncidentStatus $status, string $message): IncidentUpdate
    {
        $text = $this->text($message);

        return $this->locked($teamId, $incidentId, $actor, function (Incident $incident, CarbonImmutable $now) use ($actor, $status, $text): IncidentUpdate {
            if ($incident->status === $status) {
                return $this->lifecycle->record($incident, $actor, $text, $now);
            }

            if (!$incident->status->isOpen()) {
                throw new IncidentChangeRejected('Only a newly confirmed failure can reopen a resolved incident.');
            }

            if ($status === IncidentStatus::Resolved) {
                return $this->lifecycle->resolve($incident, $actor, $text, $now);
            }

            $incident->forceFill(['status' => $status])->save();

            return $this->lifecycle->record($incident, $actor, $text, $now);
        });
    }

    /**
     * @param User $actor Manager changing the impact.
     * @param int $teamId Active team.
     * @param int $incidentId Incident of that team.
     * @param IncidentSeverity $severity Replacement severity; an unchanged value records nothing.
     * @return Incident Incident with the requested severity.
     */
    public function changeSeverity(User $actor, int $teamId, int $incidentId, IncidentSeverity $severity): Incident
    {
        return $this->locked($teamId, $incidentId, $actor, function (Incident $incident, CarbonImmutable $now) use ($actor, $severity): Incident {
            if ($incident->severity === $severity) {
                return $incident;
            }

            $message = "Severity changed from {$incident->severity->value} to {$severity->value}.";
            $incident->forceFill(['severity' => $severity])->save();
            $this->lifecycle->record($incident, $actor, $message, $now);

            return $incident;
        });
    }

    /**
     * The note itself is not copied into the timeline, which may later be published.
     *
     * @param User $actor Manager declaring the cause.
     * @param int $teamId Active team.
     * @param int $incidentId Incident of that team.
     * @param string|null $note Declared cause; null or blank clears it.
     * @return Incident Incident with the declared cause.
     * @throws IncidentChangeRejected When the note is too long.
     */
    public function declareRootCause(User $actor, int $teamId, int $incidentId, ?string $note): Incident
    {
        $text = $note === null || trim($note) === '' ? null : $this->text($note);

        return $this->locked($teamId, $incidentId, $actor, function (Incident $incident, CarbonImmutable $now) use ($actor, $text): Incident {
            if ($incident->root_cause_note === $text) {
                return $incident;
            }

            $incident->forceFill(['root_cause_note' => $text])->save();
            $this->lifecycle->record($incident, $actor, $text === null ? 'Root cause cleared.' : 'Root cause declared.', $now);

            return $incident;
        });
    }

    /**
     * Idempotent for an already published entry; publication cannot be withdrawn.
     *
     * @param User $actor Manager publishing the entry.
     * @param int $teamId Active team.
     * @param int $updateId Timeline entry of that team.
     * @return IncidentUpdate Published entry.
     */
    public function publish(User $actor, int $teamId, int $updateId): IncidentUpdate
    {
        return $this->context->run($teamId, function () use ($actor, $teamId, $updateId): IncidentUpdate {
            $incidentId = IncidentUpdate::query()->findOrFail($updateId, ['id', 'incident_id'])->incident_id;

            return $this->locked($teamId, $incidentId, $actor, function (Incident $incident, CarbonImmutable $now) use ($updateId): IncidentUpdate {
                $update = IncidentUpdate::query()->where('incident_id', $incident->id)->lockForUpdate()->findOrFail($updateId);

                if (!$update->is_public) {
                    $update->forceFill(['is_public' => true, 'published_at' => $now])->save();
                }

                return $update;
            });
        });
    }

    /**
     * A missing or foreign incident raises ModelNotFoundException and an insufficient role AuthorizationException.
     *
     * @template TResult
     * @param int $teamId Active team.
     * @param int $incidentId Incident of that team; another team's identity is reported as missing.
     * @param User $actor Manager re-authorized under the locks.
     * @param Closure(Incident, CarbonImmutable): TResult $operation Change applied to the locked incident.
     * @return TResult Operation result.
     */
    private function locked(int $teamId, int $incidentId, User $actor, Closure $operation): mixed
    {
        return $this->context->run($teamId, fn (): mixed => DB::transaction(function () use ($teamId, $incidentId, $actor, $operation): mixed {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $monitorId = Incident::query()->findOrFail($incidentId, ['id', 'monitor_id'])->monitor_id;
            Monitor::query()->where('team_id', $teamId)->lockForUpdate()->findOrFail($monitorId);
            $incident = Incident::query()->lockForUpdate()->findOrFail($incidentId);
            Gate::forUser($actor)->authorize('update', $incident);

            return $operation($incident, CarbonImmutable::now('UTC'));
        }));
    }

    /**
     * @param string $value Submitted text.
     * @return string Trimmed nonblank text within the shared limit.
     * @throws IncidentChangeRejected When the text is blank or too long.
     */
    private function text(string $value): string
    {
        $text = trim($value);

        if ($text === '' || mb_strlen($text) > self::TEXT_MAX_LENGTH) {
            throw new IncidentChangeRejected('Incident text must be between 1 and '.self::TEXT_MAX_LENGTH.' characters.');
        }

        return $text;
    }
}
