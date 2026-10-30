<?php

declare(strict_types=1);

/** Authorize a private team subscription from current persisted membership rather than browser preferences. */

namespace App\Broadcasting;

use App\Models\User;
use App\Services\Identity\TeamAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Every team role may read updates; neither operator flags nor stale loaded relations grant membership. */
final readonly class TeamChannel
{
    /** @param TeamAccess $teams Shared current-membership boundary used by the team policies. */
    public function __construct(private TeamAccess $teams)
    {
    }

    /**
     * @param User $actor Identity resolved through the web guard, never a submitted user identifier.
     * @param string $teamId Canonical positive decimal team identity from the channel pattern.
     * @return bool Whether the verified actor belongs to that team now; foreign and missing teams both deny.
     */
    public function join(User $actor, string $teamId): bool
    {
        if (!$actor->hasVerifiedEmail() || preg_match('/\A[1-9][0-9]{0,18}\z/', $teamId) !== 1) {
            return false;
        }

        $id = filter_var($teamId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            return false;
        }

        try {
            $this->teams->membership($actor, $id);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }
}
