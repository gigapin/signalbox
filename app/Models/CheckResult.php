<?php

declare(strict_types=1);

/** Expose tenant-owned terminal observations without inferring up/down from infrastructure outcomes. */

namespace App\Models;

use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorStatus;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id Sequence-issued result identity; partition constraints additionally include slot_at.
 * @property int $team_id Immutable owning tenant.
 * @property int $execution_id Single ledger identity owning this result.
 * @property int $monitor_id Owning monitor.
 * @property string $region Simulated observation region.
 * @property CarbonImmutable $slot_at Immutable scheduled partition key.
 * @property int $monitor_revision Configuration observed by the acquired worker.
 * @property CheckResultKind $kind Observed, missed, infrastructure_failed or cancelled.
 * @property MonitorStatus $status Up/down only for observed outcomes; otherwise unknown.
 * @property CarbonImmutable|null $checked_at Actual target observation instant; never invented for missing work.
 * @property CarbonImmutable $recorded_at Durable recording instant.
 * @property int|null $response_time_ms Nearest integer measured milliseconds, null when not measured.
 * @property int|null $status_code Received HTTP code, otherwise null.
 * @property string|null $error_code Stable generic failure classification.
 * @property string|null $error_message Fixed safe English diagnostic.
 * @property CarbonImmutable|null $tls_expires_at Verified certificate expiry only.
 */
final class CheckResult extends Model
{
    /** Require the same scoped read and write ownership boundary as monitors. */
    use BelongsToTeam;

    /** @var bool Instants are explicitly recorded by the terminal writer. */
    public $timestamps = false;
    /** @var list<string> Result writes belong exclusively to the atomic terminal action. */
    protected $guarded = ['*'];

    /** Reject model updates; immutable observations are removed only by owned lifecycle/retention database operations. */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Recorded check results are immutable.');
        });
    }

    /** @return array<string, string> Typed outcome discriminators, scalar identities and nullable UTC observation data. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'execution_id' => 'integer',
            'monitor_id' => 'integer',
            'monitor_revision' => 'integer',
            'kind' => CheckResultKind::class,
            'status' => MonitorStatus::class,
            'slot_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'response_time_ms' => 'integer',
            'status_code' => 'integer',
            'tls_expires_at' => 'immutable_datetime',
        ];
    }
}
