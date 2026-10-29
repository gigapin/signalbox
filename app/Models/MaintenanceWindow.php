<?php

declare(strict_types=1);

/** Persist a planned maintenance window of one monitor. */

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Written by ManageMaintenanceWindows, which keeps the past immutable.
 *
 * @property int $id Window identity.
 * @property int $team_id Immutable owning tenant, equal to the monitor's team.
 * @property int $monitor_id Monitor under maintenance.
 * @property CarbonImmutable $starts_at Inclusive UTC start.
 * @property CarbonImmutable $ends_at Exclusive UTC end, after the start.
 * @property string $reason Nonblank explanation.
 * @property int|null $created_by Scheduling user; null when deleted.
 * @property CarbonImmutable|null $created_at UTC creation timestamp.
 * @property CarbonImmutable|null $updated_at UTC modification timestamp.
 * @property-read Monitor $monitor Monitor within the same tenant.
 */
final class MaintenanceWindow extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @var list<string> Windows are assigned explicitly by the owning action. */
    protected $guarded = ['*'];

    /** @return array<string, string> Scalar identities and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'monitor_id' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'created_by' => 'integer',
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

    /** @return BelongsTo<Monitor, $this> Monitor under maintenance, resolved through the tenant scope. */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * @param CarbonImmutable $at Instant to test.
     * @return bool Whether the instant lies inside [starts_at, ends_at).
     */
    public function covers(CarbonImmutable $at): bool
    {
        return !$at->isBefore($this->starts_at) && $at->isBefore($this->ends_at);
    }
}
