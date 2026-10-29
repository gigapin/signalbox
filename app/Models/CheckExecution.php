<?php

declare(strict_types=1);

/** Store private scheduling state; this record is not a check result or an incident sample. */

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id Execution identity used in queue envelopes.
 * @property int $team_id Immutable owner.
 * @property int $monitor_id Monitor owning this regional slot.
 * @property string $region Simulated queue suffix, eu or us.
 * @property CarbonImmutable $slot_at Scheduled UTC slot.
 * @property CarbonImmutable $expires_at Exclusive latest acquisition instant.
 * @property int $monitor_revision Configuration generation required before acquisition.
 * @property string $state Pending, running or a terminal delivery outcome.
 * @property string|null $publication_token Fenced publisher lease identity.
 * @property CarbonImmutable|null $publication_lease_until Publisher lease expiry.
 * @property CarbonImmutable|null $published_at Most recent confirmed queue submission.
 * @property CarbonImmutable $available_at Next eligible publication instant.
 * @property string|null $acquisition_token Fencing token for the single execution owner.
 * @property CarbonImmutable|null $started_at Acquisition instant, not proof of network traffic.
 * @property CarbonImmutable|null $deadline_at Exclusive maximum execution deadline.
 * @property CarbonImmutable|null $finished_at Terminal transition instant.
 * @property string|null $queue_uuid Redis message UUID owning the acquisition, absent for legacy direct calls.
 * @property int|null $queue_attempt Queue attempt number owning that acquisition.
 */
final class CheckExecution extends Model
{
    /** Require explicit tenant context for private queries and writes. */
    use BelongsToTeam;

    /** @var list<string> State mutations require trusted explicit assignment. */
    protected $guarded = ['*'];

    /** @return array<string, string> Immutable UTC instants and integer scalar identifiers. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'monitor_id' => 'integer',
            'monitor_revision' => 'integer',
            'queue_attempt' => 'integer',
            'slot_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'publication_lease_until' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
