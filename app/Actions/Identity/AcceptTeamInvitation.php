<?php

/** Accept only a proved, unexpired capability matching a verified account email. */

namespace App\Actions\Identity;

use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Services\Identity\InvitationLinks;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Join once without upgrading existing members or manufacturing a personal team/trial. */
final class AcceptTeamInvitation
{
    /**
     * @param InvitationLinks $links Domain resolver rechecked after locking the owning aggregate.
     * @param TenantContext $context Capability-derived context for the acceptance write.
     */
    public function __construct(private readonly InvitationLinks $links, private readonly TenantContext $context)
    {
    }

    /**
     * Accept once under the Team-then-User lock order, preserving existing membership roles.
     *
     * @param User $actor Authenticated identity; email and verification are reloaded under lock.
     * @param int $invitationId Invitation identity resolved from the server-owned session.
     * @param string $hash Capability digest proved by opening the bearer link.
     * @return Team Joined team; repeated acceptance has no additional membership effect.
     */
    public function __invoke(User $actor, int $invitationId, string $hash): Team
    {
        return DB::transaction(function () use ($actor, $invitationId, $hash): Team {
            $initial = $this->links->resolve($invitationId, $hash);

            if ($initial === null) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation is unavailable or expired. Request a new link.',
                ]);
            }

            return $this->context->run($initial->team_id, function () use ($actor, $invitationId, $hash, $initial): Team {
                $team = Team::query()->lockForUpdate()->findOrFail($initial->team_id);

                // A link valid before the lock may have been revoked or rotated by the competing transaction.
                $invitation = $this->links->resolve($invitationId, $hash);

                $user = User::query()->lockForUpdate()->findOrFail($actor->id);

                if ($invitation === null || !$user->hasVerifiedEmail() || $user->email !== $invitation->email) {
                    throw ValidationException::withMessages([
                        'invitation' => 'Sign in with the verified email address that received this invitation.',
                    ]);
                }

                $membership = Membership::query()->where('team_id', $team->id)->where('user_id', $user->id)->first();

                if ($invitation->accepted_at !== null && $membership === null) {
                    throw ValidationException::withMessages([
                        'invitation' => 'This invitation has already been used. Request a new invitation.',
                    ]);
                }

                if ($membership === null) {
                    $team->users()->attach($user->id, ['role' => $invitation->role]);
                }

                if ($invitation->accepted_at === null) {
                    $invitation->accepted_at = now()->toImmutable();
                    $invitation->save();
                    $user->current_team_id = $team->id;
                    $user->save();
                }

                return $team;
            });
        });
    }
}
