<?php

/** Change an identity preference only while its destination membership remains valid. */

namespace App\Actions\Identity;

use App\Models\Team;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use Illuminate\Support\Facades\DB;

/** Coordinate preference writes with membership revocation without implicit fallback. */
final class SwitchTeam
{
    /** @param TeamAccess $access Fresh membership lookup shared with all identity transitions. */
    public function __construct(private readonly TeamAccess $access)
    {
    }

    /**
     * Serialize selection with membership revocation; never choose a fallback team.
     *
     * @param User $actor Identity whose own preference is being changed.
     * @param int $teamId Accessible destination, independent of the existing preference.
     */
    public function __invoke(User $actor, int $teamId): void
    {
        DB::transaction(function () use ($actor, $teamId): void {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $this->access->membership($actor, $teamId);

            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $user->current_team_id = $teamId;
            $user->save();
        });
    }
}
