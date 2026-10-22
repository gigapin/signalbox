<?php

/** Serialize membership mutations and primary ownership on the team aggregate. */

namespace App\Actions\Identity;

use App\Enums\Identity\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keep role changes, departures and primary ownership consistent under concurrent requests. */
final class ManageTeamMembers
{
    /** @param TeamAccess $access Fresh membership hierarchy shared with invitation management. */
    public function __construct(private readonly TeamAccess $access)
    {
    }

    /**
     * Serialize role changes and preserve the primary and last owner.
     *
     * @param User $actor Verified member performing the role change.
     * @param int $teamId Explicit team aggregate to lock.
     * @param int $userId Existing membership identity in that team.
     * @param TeamRole $role Requested new role, never mass assigned.
     */
    public function changeRole(User $actor, int $teamId, int $userId, TeamRole $role): void
    {
        DB::transaction(function () use ($actor, $teamId, $userId, $role): void {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $this->access->membership($actor, $teamId);

            $membership = Membership::query()->where('team_id', $teamId)->where('user_id', $userId)->firstOrFail();
            $this->access->authorizeManagement($actor, $teamId, $membership->role);
            $this->access->authorizeManagement($actor, $teamId, $role);

            if ($role !== TeamRole::Owner) {
                $this->protectOwner($team, $membership);
            }

            // Query both composite-key columns explicitly; standalone Pivot models lack relation key metadata.
            Membership::query()
                ->where('team_id', $teamId)
                ->where('user_id', $userId)
                ->update(['role' => $role->value]);
        });
    }

    /**
     * Remove membership and clear its preference under the Team-then-User lock order.
     *
     * @param User $actor Verified manager or departing member.
     * @param int $teamId Team whose members are being changed.
     * @param int $userId Member to remove; self-departure needs no manager role.
     */
    public function remove(User $actor, int $teamId, int $userId): void
    {
        DB::transaction(function () use ($actor, $teamId, $userId): void {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $this->access->membership($actor, $teamId);

            $membership = Membership::query()->where('team_id', $teamId)->where('user_id', $userId)->firstOrFail();

            if ($actor->id !== $userId) {
                $this->access->authorizeManagement($actor, $teamId, $membership->role);
            }

            $this->protectOwner($team, $membership);
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            Membership::query()->where('team_id', $teamId)->where('user_id', $userId)->delete();
            User::query()
                ->whereKey($userId)
                ->where('current_team_id', $teamId)
                ->update(['current_team_id' => null]);
        });
    }

    /**
     * Transfer designation without changing either participant's owner membership.
     *
     * @param User $actor Current primary owner; recent-password confirmation belongs to the HTTP boundary.
     * @param int $teamId Team whose primary ownership is transferred.
     * @param int $successorId Different existing owner; no implicit promotion occurs.
     */
    public function transfer(User $actor, int $teamId, int $successorId): void
    {
        DB::transaction(function () use ($actor, $teamId, $successorId): void {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $this->access->membership($actor, $teamId);

            if ($team->owner_id !== $actor->id) {
                throw new AuthorizationException('Only the current primary owner may transfer ownership.');
            }

            if ($successorId === $actor->id) {
                throw ValidationException::withMessages([
                    'user_id' => 'Choose another existing owner.',
                ]);
            }

            $successor = User::query()->findOrFail($successorId);
            $successorRole = $this->access->membership($successor, $teamId)->role;

            if ($successorRole !== TeamRole::Owner) {
                throw ValidationException::withMessages([
                    'user_id' => 'Choose another existing owner.',
                ]);
            }

            $team->owner_id = $successorId;
            $team->save();
        });
    }

    /**
     * @param Team $team Locked team whose designated owner must retain an owner membership.
     * @param Membership $membership Target that is about to leave or cease being an owner.
     * @throws ValidationException When the primary or last owner would be lost.
     */
    private function protectOwner(Team $team, Membership $membership): void
    {
        if ($team->owner_id === $membership->user_id) {
            throw ValidationException::withMessages([
                'member' => 'Transfer primary ownership before removing or demoting the primary owner.',
            ]);
        }

        if ($membership->role !== TeamRole::Owner) {
            return;
        }

        $ownerCount = Membership::query()
            ->where('team_id', $team->id)
            ->where('role', TeamRole::Owner)
            ->count();

        if ($ownerCount <= 1) {
            throw ValidationException::withMessages([
                'member' => 'The team must retain at least one owner.',
            ]);
        }
    }
}
