<?php

declare(strict_types=1);

/** Store one region's consecutive failure sequence for a monitor. */

namespace App\Models;

use App\Data\Incidents\RegionalSequence;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Written only by incident detection inside the terminal result transaction.
 *
 * @property int $id Row identity.
 * @property int $team_id Immutable owning tenant.
 * @property int $monitor_id Monitor whose results build the sequence.
 * @property MonitorRegion $region Simulated region.
 * @property int $monitor_revision Execution revision the counters belong to.
 * @property CarbonImmutable $last_slot_at Latest processed UTC slot.
 * @property MonitorStatus $last_status Outcome of that slot.
 * @property int $consecutive_failures Consecutive observed failures ending at that slot.
 * @property CarbonImmutable|null $sequence_started_at First failing slot of the current sequence.
 * @property CheckError|null $last_error_code Cause of the latest failure in the sequence.
 */
final class MonitorRegionSequence extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @var list<string> Rows are assigned explicitly from a computed sequence. */
    protected $guarded = ['*'];

    /** @return array<string, string> Native enums, scalar counters and immutable UTC slots. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'monitor_id' => 'integer',
            'region' => MonitorRegion::class,
            'monitor_revision' => 'integer',
            'last_slot_at' => 'immutable_datetime',
            'last_status' => MonitorStatus::class,
            'consecutive_failures' => 'integer',
            'sequence_started_at' => 'immutable_datetime',
            'last_error_code' => CheckError::class,
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

    /** @return RegionalSequence Pure value of the stored sequence. */
    public function toSequence(): RegionalSequence
    {
        return new RegionalSequence(
            $this->region,
            $this->monitor_revision,
            $this->last_slot_at,
            $this->last_status,
            $this->consecutive_failures,
            $this->sequence_started_at,
            $this->last_error_code,
        );
    }

    /** @param RegionalSequence $sequence Advanced sequence of the same monitor and region to store. */
    public function store(RegionalSequence $sequence): void
    {
        $this->forceFill([
            'region' => $sequence->region,
            'monitor_revision' => $sequence->revision,
            'last_slot_at' => $sequence->lastSlotAt,
            'last_status' => $sequence->lastStatus,
            'consecutive_failures' => $sequence->consecutiveFailures,
            'sequence_started_at' => $sequence->startedAt,
            'last_error_code' => $sequence->lastError,
        ])->save();
    }
}
