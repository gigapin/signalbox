<?php

/** Exercise the reusable tenant model contract on a disposable PostgreSQL relation. */

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represent synthetic private content without introducing a product resource before M2.
 *
 * @property int $id Fixture identity.
 * @property int $team_id Immutable owning team.
 * @property string $title Synthetic content.
 */
final class TenantRecord extends Model
{
    /** Enforce the same scope and write guards used by private invitations. */
    use BelongsToTeam;

    /** @var string $table Transaction-local fixture relation. */
    protected $table = 'signalbox_tenant_records';

    /** @var bool $timestamps Fixture records have no timestamps. */
    public $timestamps = false;

    /** @var list<string> $guarded Trusted test inputs exercise the independent write guard. */
    protected $guarded = [];

    /** @return BelongsTo<Team, $this> Explicit owner; inverse traversal never grants another team. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
