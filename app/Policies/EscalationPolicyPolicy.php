<?php

declare(strict_types=1);

/** Authorize escalation policy reads and changes from the actor's current membership role. */

namespace App\Policies;

use App\Enums\Identity\TeamRole;
use App\Models\EscalationPolicy;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;

/**
 * Policies decide who is woken up for every monitor, so they follow the channel rule: owners and admins manage
 * them (user decision), every member reads them. Choosing a policy for a monitor is monitor configuration and
 * is authorized by MonitorPolicy::update instead.
 */
final class EscalationPolicyPolicy
{
    /** @var list<TeamRole> Roles allowed to manage escalation policies. */
    private const array MANAGERS = [TeamRole::Owner, TeamRole::Admin];

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
     * @return bool Whether the fresh role is owner or admin.
     */
    public function create(User $actor): bool
    {
        return in_array($this->access->membership($actor, $this->context->requireTeamId())->role, self::MANAGERS, true);
    }

    /**
     * @param User $actor Requesting account.
     * @param EscalationPolicy $policy Policy loaded inside the active context; foreign policies raise a 404.
     * @return bool Whether the fresh role is owner or admin.
     */
    public function update(User $actor, EscalationPolicy $policy): bool
    {
        abort_unless($policy->team_id === $this->context->requireTeamId(), 404);

        return $this->create($actor);
    }

    /**
     * @param User $actor Requesting account.
     * @param EscalationPolicy $policy Local policy.
     * @return bool Whether the fresh role is owner or admin.
     */
    public function delete(User $actor, EscalationPolicy $policy): bool
    {
        return $this->update($actor, $policy);
    }
}
