<?php

/** Authorize the existing team surface using current persisted membership rules. */

namespace App\Policies;

use App\Enums\Identity\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Identity\TeamAccess;

/** Policies gate HTTP operations; lock-aware actions independently recheck mutable authority. */
final class TeamPolicy
{
    /** @param TeamAccess $access Shared fresh membership and role hierarchy. */
    public function __construct(private readonly TeamAccess $access)
    {
    }

    /**
     * @param User $actor Requesting account.
     * @param Team $team Explicit team, independent of the current preference.
     * @return bool Whether a current membership exists; missing membership raises a 404.
     */
    public function view(User $actor, Team $team): bool
    {
        $this->access->membership($actor, $team->id);

        return true;
    }

    /**
     * @param User $actor Manager requesting a membership transition.
     * @param Team $team Owning aggregate.
     * @param TeamRole $target Existing or requested role.
     * @return bool Whether this role may be managed.
     */
    public function manageRole(User $actor, Team $team, TeamRole $target): bool
    {
        return $this->access->canManage($this->access->membership($actor, $team->id)->role, $target);
    }

    /**
     * @param User $actor Manager or departing account.
     * @param Team $team Owning aggregate.
     * @param User $member Existing participant.
     * @return bool Whether departure or management is authorized, before domain ownership invariants.
     */
    public function removeMember(User $actor, Team $team, User $member): bool
    {
        $this->access->membership($actor, $team->id);
        $target = $this->access->membership($member, $team->id)->role;

        return $actor->id === $member->id || $this->manageRole($actor, $team, $target);
    }

    /**
     * @param User $actor Requesting account.
     * @param Team $team Team whose designated ownership may change.
     * @return bool Whether the actor is the current primary owner.
     */
    public function transferOwnership(User $actor, Team $team): bool
    {
        $this->access->membership($actor, $team->id);

        return $team->owner_id === $actor->id;
    }
}
