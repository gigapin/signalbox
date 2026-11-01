<?php

declare(strict_types=1);

/** Authorize status page reads and changes from the actor's current membership role. */

namespace App\Policies;

use App\Enums\Identity\TeamRole;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;

/**
 * A status page publishes information outside the team, so it follows the channel rule: owners and admins manage
 * pages, every member reads them.
 */
final class StatusPagePolicy
{
    /** @var list<TeamRole> Roles allowed to manage status pages. */
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
     * @param StatusPage $page Page loaded inside the active context; foreign pages raise a 404.
     * @return bool Whether the fresh role is owner or admin.
     */
    public function update(User $actor, StatusPage $page): bool
    {
        abort_unless($page->team_id === $this->context->requireTeamId(), 404);

        return $this->create($actor);
    }

    /**
     * @param User $actor Requesting account.
     * @param StatusPage $page Local page.
     * @return bool Whether the fresh role is owner or admin.
     */
    public function delete(User $actor, StatusPage $page): bool
    {
        return $this->update($actor, $page);
    }
}
