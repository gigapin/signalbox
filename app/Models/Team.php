<?php

/** Define tenant identity, primary ownership and stored trial history. */

namespace App\Models;

use App\Enums\Billing\Plan;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Store team configuration without resolving paid subscription rights or tenant context.
 *
 * @property int $id Unique tenant identity.
 * @property string $name Human-readable team name.
 * @property string $slug Globally unique lowercase URL identifier.
 * @property int $owner_id Primary owner, also represented by an owner membership.
 * @property Plan $plan Stored base plan, not the effective trial or paid entitlement.
 * @property string $timezone IANA timezone for team calendar operations.
 * @property \Carbon\CarbonImmutable $trial_started_at Original UTC trial start, retained after expiration.
 * @property \Carbon\CarbonImmutable $trial_ends_at Exclusive UTC boundary of the initial trial.
 * @property \Carbon\CarbonImmutable|null $created_at Team creation timestamp in UTC.
 * @property \Carbon\CarbonImmutable|null $updated_at Last team modification in UTC.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Monitor> $monitors Monitors visible only within the active tenant context.
 * @property-read User $owner Primary owner identity.
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users Users with explicit membership.
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> Produce coherent synthetic team fixtures. */
    use HasFactory;

    /** @var list<string> $fillable Non-sensitive team presentation settings. */
    protected $fillable = ['name', 'slug', 'timezone'];

    /** @return array<string, string> Plan and immutable UTC timestamp casts. */
    protected function casts(): array
    {
        return [
            'plan' => Plan::class,
            'trial_started_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> Designated owner without implying exclusive ownership. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this, Membership> Explicit members with typed pivot roles. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(Membership::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Monitor, $this> Tenant-scoped monitors; traversing another team's relation grants no access. */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

}
