<?php

declare(strict_types=1);

/** Authorize notification channel reads and changes from the actor's current membership role. */

namespace App\Policies;

use App\Enums\Identity\TeamRole;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;

/**
 * Channels hold team-wide credentials, so only owners and admins create, change, verify, activate and delete
 * them (user decision). Every member sees the list with masked destinations.
 */
final class NotificationChannelPolicy
{
    /** @var list<TeamRole> Roles allowed to manage channels. */
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
     * @param NotificationChannel $channel Channel loaded inside the active context; foreign channels raise a 404.
     * @return bool Whether the fresh role is owner or admin.
     */
    public function update(User $actor, NotificationChannel $channel): bool
    {
        abort_unless($channel->team_id === $this->context->requireTeamId(), 404);

        return $this->create($actor);
    }

    /**
     * @param User $actor Requesting account.
     * @param NotificationChannel $channel Local channel.
     * @return bool Whether the fresh role is owner or admin.
     */
    public function delete(User $actor, NotificationChannel $channel): bool
    {
        return $this->update($actor, $channel);
    }
}
