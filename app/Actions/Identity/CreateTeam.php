<?php

/** Create a coherent team aggregate without depending on HTTP or session transport. */

namespace App\Actions\Identity;

use App\Enums\Billing\Plan;
use App\Enums\Identity\TeamRole;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Keep initial ownership, trial and optional selection in one transaction. */
final class CreateTeam
{
    /**
     * Create a team for a persisted owner and preserve any existing team preference.
     *
     * @param User $owner Persisted identity requesting the team; callers authorize their actor.
     * @param string $name Team display name, normalized by trimming outer whitespace.
     * @param string $slug Explicit globally unique lowercase slug, never silently renamed.
     * @param string $timezone IANA calendar timezone; UTC is the default.
     * @return Team Persisted aggregate with its initial owner membership.
     * @throws \Illuminate\Validation\ValidationException When presentation inputs are invalid.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When the owner no longer exists.
     * @throws \Illuminate\Database\QueryException When uniqueness or persistence fails; all writes roll back.
     */
    public function __invoke(User $owner, string $name, string $slug, string $timezone = 'UTC'): Team
    {
        // Validate transport-independent input before entering the write transaction.
        Validator::make(['name' => trim($name), 'slug' => $slug, 'timezone' => $timezone], [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'timezone' => ['required', 'timezone:all'],
        ])->validate();

        return DB::transaction(/** Create only after serializing initial selection for this owner. */ function () use ($owner, $name, $slug, $timezone): Team {
            /** @var User $lockedOwner Fresh identity under row lock; the caller's model may be stale. */
            $lockedOwner = User::query()->lockForUpdate()->findOrFail($owner->getKey());
            /** @var CarbonImmutable $startedAt Single UTC reference shared by creation and trial timestamps. */
            $startedAt = CarbonImmutable::now('UTC');
            /** @var Team $team New aggregate; entitlement attributes are assigned only by trusted code. */
            $team = new Team(['name' => trim($name), 'slug' => $slug, 'timezone' => $timezone]);
            $team->owner()->associate($lockedOwner);
            $team->plan = Plan::Free;
            $team->created_at = $startedAt;
            $team->trial_started_at = $startedAt;
            $team->trial_ends_at = $startedAt->addDays(14);
            $team->save();
            $team->users()->attach($lockedOwner->getKey(), ['role' => TeamRole::Owner]);

            if ($lockedOwner->current_team_id === null) {
                $lockedOwner->current_team_id = $team->id;
                $lockedOwner->save();
            }

            return $team;
        });
    }
}
