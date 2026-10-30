<?php

declare(strict_types=1);

/** Expose one monitor's rollup for one UTC hour through the tenant boundary. */

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Read-only from models: the rollup builder is the single writer.
 *
 * @property int $team_id Immutable owning tenant.
 * @property int $monitor_id Monitor the hour belongs to.
 * @property CarbonImmutable $hour Inclusive UTC hour start.
 * @property int $checks Regional executions reserved for slots in the hour.
 * @property int $failures Regional observations that found the target down.
 * @property int $unknown_checks Regional executions without an observation.
 * @property int $up_us Agreed up time in microseconds, after exclusions.
 * @property int $down_us Agreed down time in microseconds, after exclusions.
 * @property int $unknown_us Eligible time without a valid verdict, in microseconds.
 * @property int $excluded_us Monitored time inside pauses, suspensions or maintenance, in microseconds.
 * @property float|null $uptime_ratio Up time over up plus down time; null without observed time.
 * @property int $latency_samples Measured latencies in the hour.
 * @property int $latency_sum_ms Sum of the measured latencies in milliseconds.
 * @property array<int, int> $latency_counts Sample count per millisecond value.
 * @property float|null $avg_ms Mean latency; null without samples.
 * @property int|null $p50_ms Exact median latency; null without samples.
 * @property int|null $p95_ms Exact 95th percentile latency; null without samples.
 */
final class CheckRollupHourly extends Model
{
    /** Apply the fail-closed tenant query boundary. */
    use BelongsToTeam;

    /** @var bool Rollups have no lifecycle timestamps: they are replaced as a whole. */
    public $timestamps = false;

    /** @var bool The natural key is (monitor_id, hour). */
    public $incrementing = false;

    /** @var string Backing table, named after the specification rather than the model. */
    protected $table = 'check_rollups_hourly';

    /** @var list<string> Nothing is assignable; the builder writes every row. */
    protected $guarded = ['*'];

    /** Reject model writes so rollups only come from the raw results through the builder. */
    protected static function booted(): void
    {
        static::saving(static function (): never {
            throw new LogicException('Check rollups are written only by the rollup builder.');
        });
    }

    /** @return array<string, string> Scalar metrics, the frequency map and the immutable UTC hour. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'monitor_id' => 'integer',
            'hour' => 'immutable_datetime',
            'checks' => 'integer',
            'failures' => 'integer',
            'unknown_checks' => 'integer',
            'up_us' => 'integer',
            'down_us' => 'integer',
            'unknown_us' => 'integer',
            'excluded_us' => 'integer',
            'uptime_ratio' => 'float',
            'latency_samples' => 'integer',
            'latency_sum_ms' => 'integer',
            'latency_counts' => 'array',
            'avg_ms' => 'float',
            'p50_ms' => 'integer',
            'p95_ms' => 'integer',
        ];
    }
}
