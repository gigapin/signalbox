<?php

/** Resolve fresh membership permissions independently of browser team preferences. */

namespace App\Services\Identity;

use App\Enums\Identity\TeamRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Centralize M1.03 membership rules; general resource policies remain M1.04. */
final class TeamAccess
{
    /**
     * @param User $actor Authenticated identity, never inferred from a submitted user ID.
     * @param int $teamId Explicit team whose membership must exist now.
     * @return Membership Fresh membership; inaccessible teams are indistinguishable from missing teams.
     */
    public function membership(User $actor, int $teamId): Membership
    {
        return Membership::query()->where('team_id', $teamId)->where('user_id', $actor->id)->firstOrFail();
    }

    /**
     * @param TeamRole $actor Current persisted role of the requesting member.
     * @param TeamRole $target Existing or requested role being managed.
     * @return bool Whether the role hierarchy permits this management operation.
     */
    public function canManage(TeamRole $actor, TeamRole $target): bool
    {
        if ($actor === TeamRole::Owner) {
            return true;
        }

        return $actor === TeamRole::Admin
            && in_array($target, [TeamRole::Member, TeamRole::Viewer], true);
    }

    /**
     * @param User $actor Identity initiating a protected membership transition.
     * @param int $teamId Team already locked by the mutation action.
     * @param TeamRole $target Role being affected, including requested promotion destinations.
     */
    public function authorizeManagement(User $actor, int $teamId, TeamRole $target): void
    {
        if (!$this->canManage($this->membership($actor, $teamId)->role, $target)) {
            throw new AuthorizationException('Your current team role cannot manage this membership role.');
        }
    }
}
