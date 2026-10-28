<?php

declare(strict_types=1);

/** Persist a tenant-owned confirmed monitor failure, its responsibility, impact, cause and lifecycle. */

namespace App\Models;

use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\CheckError;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Writes belong to the incident actions; the model only rejects lifecycle changes the status graph forbids.
 *
 * @property int $id Incident identity.
 * @property int $team_id Immutable owning tenant, equal to the monitor's team.
 * @property int $monitor_id Failing monitor; at most one unresolved incident exists per monitor.
 * @property IncidentStatus $status Current lifecycle status.
 * @property IncidentSeverity $severity Impact, derived from the cause on opening and adjustable later.
 * @property CheckError $cause Stable classification of the confirming failure.
 * @property string|null $root_cause_note Nonblank cause declared by people, never inferred.
 * @property CarbonImmutable $started_at UTC slot of the first failure in the confirmed sequence.
 * @property CarbonImmutable $confirmed_at UTC instant the threshold confirmation opened the incident.
 * @property CarbonImmutable|null $acknowledged_at UTC instant responsibility was taken.
 * @property int|null $acknowledged_by Responsible user; null when unacknowledged or the user was deleted.
 * @property CarbonImmutable|null $resolved_at UTC instant of the latest resolution; null while open.
 * @property int|null $duration_seconds Seconds from started_at to resolved_at; null while open.
 * @property int $reopened_count Number of reopenings within the configured reopen window.
 * @property CarbonImmutable|null $last_reopened_at UTC instant of the latest reopening.
 * @property CarbonImmutable|null $created_at UTC creation timestamp.
 * @property CarbonImmutable|null $updated_at UTC modification timestamp.
 * @property-read Monitor $monitor Failing monitor within the same tenant.
 * @property-read User|null $acknowledgedBy Responsible user, when any.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IncidentUpdate> $updates Timeline entries.
 */
final class Incident extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @use HasFactory<IncidentFactory> Synthetic incidents created only inside a tenant context. */
    use HasFactory;

    /** @var list<string> Lifecycle writes require explicit assignment by the owning actions. */
    protected $guarded = ['*'];

    /** @var array<string, mixed> Opening state shared by automatic incidents. */
    protected $attributes = [
        'status' => 'investigating',
        'reopened_count' => 0,
    ];

    /** Reject lifecycle changes outside the status graph even when trusted code assigns properties directly. */
    protected static function booted(): void
    {
        static::saving(static function (self $incident): void {
            $incident->guardLifecycle();
        });
    }

    /** @return array<string, string> Native enums, scalar identities and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'monitor_id' => 'integer',
            'status' => IncidentStatus::class,
            'severity' => IncidentSeverity::class,
            'cause' => CheckError::class,
            'started_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'acknowledged_by' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
            'reopened_count' => 'integer',
            'last_reopened_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Normalize instants before Laravel formats timestamp values without a timezone offset.
     *
     * @param mixed $value Date value accepted by Eloquent, including null and empty attributes.
     * @return string|null UTC timestamp for PostgreSQL, preserving null and empty values.
     */
    public function fromDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    /** @return BelongsTo<Monitor, $this> Failing monitor, resolved through the tenant scope. */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** @return BelongsTo<User, $this> Responsible user; identity only, never an authorization grant. */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return HasMany<IncidentUpdate, $this> Append-only timeline, oldest first. */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->oldest('created_at')->oldest('id');
    }

    /**
     * New incidents start investigating; a status change must be an allowed transition, and a reopening must
     * increment the reopen counter so the timeline and the record cannot disagree.
     *
     * @throws LogicException When the persisted or requested lifecycle is not admitted.
     */
    private function guardLifecycle(): void
    {
        if (!$this->exists) {
            if ($this->status !== IncidentStatus::Investigating) {
                throw new LogicException('An incident must be opened in the investigating status.');
            }

            return;
        }

        if (!$this->isDirty('status')) {
            return;
        }

        $previous = IncidentStatus::from((string) $this->getRawOriginal('status'));

        if (!$previous->canTransitionTo($this->status)) {
            throw new LogicException("An incident cannot move from {$previous->value} to {$this->status->value}.");
        }

        $reopening = $previous === IncidentStatus::Resolved;

        if ($reopening && $this->reopened_count !== (int) $this->getRawOriginal('reopened_count') + 1) {
            throw new LogicException('A reopening must increment the reopen counter exactly once.');
        }
    }
}
