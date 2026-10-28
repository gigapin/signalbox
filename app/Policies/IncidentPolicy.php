<?php

declare(strict_types=1);

/** Authorize incident reads and manual incident changes from the actor's current membership role. */

namespace App\Policies;

use App\Enums\Identity\TeamRole;
use App\Models\Incident;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;

/** Owners, admins and members handle incidents, like monitors; viewers only read. */
final class IncidentPolicy
{
    /** @var list<TeamRole> Roles allowed to acknowledge, update, describe and publish incidents. */
    private const array MANAGERS = [TeamRole::Owner, TeamRole::Admin, TeamRole::Member];

    /**
     * @param TeamAccess $access Fresh membership resolver.
     * @param TenantContext $context Active team; absent context denies by raising.
     */
    public function __construct(private readonly TeamAccess $access, private readonly TenantContext $context)
    {
    }

    /**
     * @param User $actor Requesting account.
     * @return bool Whether any current membership exists.
     */
    public function viewAny(User $actor): bool
    {
        $this->access->membership($actor, $this->context->requireTeamId());

        return true;
    }

    /**
     * @param User $actor Requesting account.
     * @param Incident $incident Incident loaded inside the active context; foreign incidents raise a 404.
     * @return bool Whether any current membership exists.
     */
    public function view(User $actor, Incident $incident): bool
    {
        $this->assertLocal($incident);

        return $this->viewAny($actor);
    }

    /**
     * @param User $actor Requesting account.
     * @param Incident $incident Local incident.
     * @return bool Whether the fresh role is owner, admin or member.
     */
    public function update(User $actor, Incident $incident): bool
    {
        $this->assertLocal($incident);

        return in_array($this->access->membership($actor, $this->context->requireTeamId())->role, self::MANAGERS, true);
    }

    /** @param Incident $incident Candidate resource; another team's incident is indistinguishable from a missing one. */
    private function assertLocal(Incident $incident): void
    {
        abort_unless($incident->team_id === $this->context->requireTeamId(), 404);
    }
}
