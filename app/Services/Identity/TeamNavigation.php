<?php

declare(strict_types=1);

/** Project current memberships and a valid account preference without loading tenant resources. */

namespace App\Services\Identity;

use App\Enums\Identity\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A single membership query supplies all selector roles; no team model or secrets are serialized. */
final readonly class TeamNavigation
{
    /**
     * @param User $actor Verified account whose current memberships are read afresh.
     * @return array{teams: list<array{id: int, name: string, role: string}>, selectedTeamId: int|null} Safe choices and preference, with no implicit fallback.
     */
    public function forUser(User $actor): array
    {
        $rows = DB::table('team_user')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->where('team_user.user_id', $actor->id)
            ->orderBy('teams.name')
            ->orderBy('teams.id')
            ->get(['teams.id', 'teams.name', 'team_user.role']);
        $teams = [];

        foreach ($rows as $row) {
            $teams[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'role' => TeamRole::from((string) $row->role)->value,
            ];
        }

        $preference = User::query()->whereKey($actor->id)->value('current_team_id');
        $selected = $preference === null ? null : (int) $preference;

        return [
            'teams' => $teams,
            'selectedTeamId' => in_array($selected, array_column($teams, 'id'), true) ? $selected : null,
        ];
    }
}
