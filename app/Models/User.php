<?php

/**
 * Define authenticatable identity and membership-based team resolution.
 */

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Store user identity while hiding authentication secrets from serialization.
 *
 * @property int|null $current_team_id Optional preference; membership must still be verified.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $teams Teams reached through explicit membership.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $ownedTeams Teams for which this user is the primary owner.
 * @property int $id Unique user identifier.
 * @property string $name Display name.
 * @property string $email Unique authentication email address.
 * @property string $password Hashed authentication password.
 * @property string|null $remember_token Optional persistent-session token.
 * @property \Illuminate\Support\Carbon|null $email_verified_at Email verification timestamp.
 * @property \Illuminate\Support\Carbon|null $created_at Creation timestamp in UTC.
 * @property \Illuminate\Support\Carbon|null $updated_at Last modification timestamp in UTC.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> Generate synthetic users through the dedicated factory. */
    use HasFactory;

    /** Issue hashed API tokens with explicit abilities; tenant policy enforcement belongs to M10. */
    use HasApiTokens;

    use Notifiable;

    /** @return BelongsToMany<Team, $this, Membership> Accessible identities with typed membership roles; policies still apply. */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->using(Membership::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Team, $this> Teams with this designated primary owner. */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    /**
     * Resolve the persisted preference through current membership, without trusting stale loaded relations.
     *
     * @return Team|null Selected team when membership still exists; otherwise no implicit fallback.
     */
    public function resolveCurrentTeam(): ?Team
    {
        // Read the current preference afresh so revoked or changed selections are not reused by a stale model.
        /** @var int|null $teamId Persisted selection, independent of authorization and request tenant context. */
        $teamId = self::query()->whereKey($this->getKey())->value('current_team_id');

        return $teamId === null ? null : $this->teams()->whereKey($teamId)->first();
    }

    /**
     * Cast timestamps and hash passwords assigned through the model.
     *
     * @return array<string, string> Attribute names mapped to framework cast types.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'current_team_id' => 'integer',
        ];
    }
}
