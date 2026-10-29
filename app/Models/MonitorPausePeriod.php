<?php

declare(strict_types=1);

/** Expose historical pause and plan suspension periods recorded by the monitors trigger. */

namespace App\Models;

use App\Enums\Monitoring\PauseKind;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Read-only from the application: the database trigger is the single writer, so no write path can skip history.
 *
 * @property int $id Period identity.
 * @property int $team_id Immutable owning tenant.
 * @property int $monitor_id Paused or suspended monitor.
 * @property PauseKind $kind Voluntary pause or plan suspension.
 * @property CarbonImmutable $started_at UTC transaction instant the flag became true.
 * @property CarbonImmutable|null $ended_at Exclusive UTC end; null while still active.
 */
final class MonitorPausePeriod extends Model
{
    /** Apply the fail-closed tenant query boundary. */
    use BelongsToTeam;

    /** @var bool Periods carry explicit instants only. */
    public $timestamps = false;

    /** @var list<string> Nothing is assignable; the trigger writes every row. */
    protected $guarded = ['*'];

    /** Reject application writes so history comes only from flag changes on monitors. */
    protected static function booted(): void
    {
        static::saving(static function (): never {
            throw new LogicException('Pause periods are recorded by the database when monitor flags change.');
        });
    }

    /** @return array<string, string> Kind enum, scalar identities and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'monitor_id' => 'integer',
            'kind' => PauseKind::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
