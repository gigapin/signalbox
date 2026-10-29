<?php

/** Represent one user's persisted role in a team. */

namespace App\Models;

use App\Enums\Identity\TeamRole;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Cast membership roles for explicit identity actions; general resource policies remain M1.04.
 *
 * @property int $team_id Team participating in this unique pair.
 * @property int $user_id User participating in this unique pair.
 * @property TeamRole $role Persisted role in this team only.
 * @property \Illuminate\Support\Carbon|null $created_at Membership creation time in UTC.
 * @property \Illuminate\Support\Carbon|null $updated_at Last membership update in UTC.
 */
class Membership extends Pivot
{
    /** @use HasFactory<MembershipFactory> Generate explicit synthetic role assignments. */
    use HasFactory;

    /** @var string $table Conventional membership table shared with both many-to-many relations. */
    protected $table = 'team_user';

    /** @var list<string> $fillable Attributes accepted by trusted membership orchestration. */
    protected $fillable = ['team_id', 'user_id', 'role'];

    /** @return array<string, string> Enum conversion for the persisted role. */
    protected function casts(): array
    {
        return ['role' => TeamRole::class];
    }

    /** @return BelongsTo<Team, $this> Team associated with this membership. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> User associated with this membership. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
