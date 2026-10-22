<?php

/** Gate private invitation management without authorizing public capability acceptance. */

namespace App\Policies;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;

/** Preserve the owner/admin hierarchy used by lock-aware invitation actions and UI projections. */
final class TeamInvitationPolicy
{
    /**
     * @param TeamAccess $access Fresh membership hierarchy.
     * @param TenantContext $context Explicit owning team, required for private operations.
     */
    public function __construct(private readonly TeamAccess $access, private readonly TenantContext $context)
    {
    }

    /**
     * @param User $actor Current manager.
     * @param TeamInvitation $invitation Team-local invitation loaded inside context.
     * @return bool Whether its existing role is manageable; cross-tenant references are hidden.
     */
    public function manage(User $actor, TeamInvitation $invitation): bool
    {
        abort_unless($invitation->team_id === $this->context->requireTeamId(), 404);

        return $this->access->canManage($this->access->membership($actor, $invitation->team_id)->role, $invitation->role);
    }
}
