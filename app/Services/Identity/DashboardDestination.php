<?php

declare(strict_types=1);

/** Resolve a default dashboard using fresh memberships rather than an untrusted preference. */

namespace App\Services\Identity;

use App\Models\User;

/** Share the authenticated landing rule across password and second-factor sessions. */
final readonly class DashboardDestination
{
    /** @param TeamNavigation $navigation Fresh membership and preference projection. */
    public function __construct(private TeamNavigation $navigation)
    {
    }

    /**
     * @param User $actor Authenticated account; destination middleware still enforces verification.
     * @return string Authorized team dashboard, or the team-neutral dashboard chooser.
     */
    public function forUser(User $actor): string
    {
        $navigation = $this->navigation->forUser($actor);
        $team = $navigation['selectedTeamId'];

        if ($team === null && count($navigation['teams']) === 1) {
            $team = $navigation['teams'][0]['id'];
        }

        return $team === null ? '/dashboard' : '/teams/'.$team.'/dashboard';
    }
}
