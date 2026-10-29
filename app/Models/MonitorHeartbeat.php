<?php

declare(strict_types=1);

/** Store private heartbeat arming state and a one-way credential digest. */

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id State identity, never a bearer credential.
 * @property int $team_id Immutable owning tenant.
 * @property int $monitor_id Heartbeat monitor identity.
 * @property string|null $token_hash SHA-256 digest; the original credential is never stored.
 * @property int $generation Current arming generation, changed by timing edits or a real resume.
 * @property CarbonImmutable $started_at UTC start of the current observation window.
 * @property CarbonImmutable|null $last_received_at Latest accepted server receipt in this generation.
 */
final class MonitorHeartbeat extends Model
{
    /** Require the same fail-closed tenant context used by monitors. */
    use BelongsToTeam;

    /** @var bool State has explicit instants rather than presentation timestamps. */
    public $timestamps = false;
    /** @var list<string> Only authorized actions may assign heartbeat state. */
    protected $guarded = ['*'];
    /** @var list<string> Never serialize credential digests or private state implicitly. */
    protected $hidden = ['token_hash'];
    /** @var string Preserve microseconds for deterministic temporal cutoffs and receipt deduplication. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> Scalar identities, generation and immutable UTC receipt instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'monitor_id' => 'integer',
            'generation' => 'integer',
            'started_at' => 'immutable_datetime',
            'last_received_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param mixed $value Date value accepted by Eloquent, including null.
     * @return string|null UTC timestamp preserving microseconds and nullability.
     */
    public function fromDateTime($value): ?string
    {
        return $value === null || $value === '' ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }
}
