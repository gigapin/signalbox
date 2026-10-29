<?php

declare(strict_types=1);

/** Authorize monitor reads and mutations from the actor's current membership role. */

namespace App\Policies;

use App\Enums\Identity\TeamRole;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;

/**
 * Owners, admins and members manage monitors; viewers only read. Plan limits are checked separately
 * by MonitorLimits inside the lock-aware actions, which also re-run this policy.
 */
final class MonitorPolicy
{
    /** @var list<TeamRole> Roles allowed to create, change, pause, resume and delete monitors. */
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
     * @param Monitor $monitor Monitor loaded inside the active context; foreign monitors raise a 404.
     * @return bool Whether any current membership exists.
     */
    public function view(User $actor, Monitor $monitor): bool
    {
        $this->assertLocal($monitor);

        return $this->viewAny($actor);
    }

    /**
     * @param User $actor Requesting account.
     * @return bool Whether the current role manages monitors.
     */
    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    /**
     * @param User $actor Requesting account.
     * @param Monitor $monitor Local monitor.
     * @return bool Whether the role manages monitors.
     */
    public function update(User $actor, Monitor $monitor): bool
    {
        $this->assertLocal($monitor);

        return $this->manages($actor);
    }

    /**
     * @param User $actor Requesting account.
     * @param Monitor $monitor Local monitor.
     * @return bool Whether the role manages monitors.
     */
    public function delete(User $actor, Monitor $monitor): bool
    {
        return $this->update($actor, $monitor);
    }

    /**
     * @param User $actor Requesting account.
     * @param Monitor $monitor Local monitor.
     * @return bool Whether the role manages monitors.
     */
    public function pause(User $actor, Monitor $monitor): bool
    {
        return $this->update($actor, $monitor);
    }

    /**
     * @param User $actor Requesting account.
     * @param Monitor $monitor Local monitor.
     * @return bool Whether the role manages monitors.
     */
    public function resume(User $actor, Monitor $monitor): bool
    {
        return $this->update($actor, $monitor);
    }

    /**
     * @param User $actor Requesting account.
     * @return bool Whether the fresh role is owner, admin or member.
     */
    private function manages(User $actor): bool
    {
        return in_array($this->access->membership($actor, $this->context->requireTeamId())->role, self::MANAGERS, true);
    }

    /** @param Monitor $monitor Candidate resource; another team's monitor is indistinguishable from a missing one. */
    private function assertLocal(Monitor $monitor): void
    {
        abort_unless($monitor->team_id === $this->context->requireTeamId(), 404);
    }
}
