<?php

/** Create, rotate and revoke invitation capabilities under the shared team lock. */

namespace App\Actions\Identity;

use App\Enums\Identity\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Keep invitation persistence atomic while email delivery happens only after commit. */
final class IssueTeamInvitation
{
    /**
     * @param TeamAccess $access Shared role hierarchy, rechecked after taking the team lock.
     * @param TenantContext $context Explicit context for direct action calls as well as HTTP.
     */
    public function __construct(private readonly TeamAccess $access, private readonly TenantContext $context)
    {
    }

    /**
     * Create or rotate a capability under the Team lock and send its link only after commit.
     *
     * @param User $actor Verified sender authorized for both existing and requested invitation roles.
     * @param int $teamId Explicit destination aggregate.
     * @param string $email Intended recipient; normalized and validated at the domain boundary.
     * @param TeamRole $role Role to grant on first acceptance.
     * @param int|null $resendId Existing invitation for explicit rotation; null creates or renews a nonpending lifecycle.
     * @return TeamInvitation Persisted invitation without its transient token.
     */
    public function __invoke(
        User $actor,
        int $teamId,
        string $email,
        TeamRole $role,
        ?int $resendId = null,
    ): TeamInvitation {
        return $this->context->run($teamId, fn (): TeamInvitation => DB::transaction(function () use ($actor, $teamId, $email, $role, $resendId): TeamInvitation {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $this->access->authorizeManagement($actor, $teamId, $role);

            $normalized = Str::lower(trim($email));
            validator(['email' => $normalized], ['email' => ['required', 'email', 'max:255']])->validate();

            $query = TeamInvitation::query()->where('team_id', $teamId);

            if ($resendId === null) {
                $invitation = $query->where('email', $normalized)->first();
            } else {
                $invitation = $query->findOrFail($resendId);
            }

            if ($invitation !== null) {
                $this->access->authorizeManagement($actor, $teamId, $invitation->role);

                if ($resendId === null && $invitation->isPending()) {
                    throw ValidationException::withMessages([
                        'email' => 'A pending invitation already exists. Use resend to send a new link.',
                    ]);
                }

                if ($resendId !== null) {
                    $normalized = $invitation->email;
                    $role = $invitation->role;
                }
            }

            if ($team->users()->where('email', $normalized)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This account is already a team member.',
                ]);
            }

            $token = Str::random(64);
            $invitation ??= new TeamInvitation();
            $invitation->forceFill([
                'team_id' => $teamId,
                'invited_by' => $actor->id,
                'email' => $normalized,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->toImmutable()->addDays(7),
                'accepted_at' => null,
                'revoked_at' => null
            ])->save();

            $url = route('invitations.open', ['invitation' => $invitation->id, 'token' => $token]);
            DB::afterCommit(static function () use ($normalized, $team, $url): void {
                try {
                    Notification::route('mail', $normalized)->notify(new TeamInvitationNotification($team->name, $url));
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'email' => 'The invitation was saved, but email delivery failed. Please resend it.',
                    ]);
                }
            });

            return $invitation;
        }));
    }

    /**
     * @param User $actor Verified sender.
     * @param int $teamId Explicit owning team.
     * @param int $invitationId Team-local invitation to invalidate.
     */
    public function revoke(User $actor, int $teamId, int $invitationId): void
    {
        $this->context->run($teamId, fn () => DB::transaction(function () use ($actor, $teamId, $invitationId): void {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $this->access->membership($actor, $teamId);

            $invitation = TeamInvitation::query()->where('team_id', $teamId)->findOrFail($invitationId);
            $this->access->authorizeManagement($actor, $teamId, $invitation->role);

            if ($invitation->accepted_at !== null) {
                throw ValidationException::withMessages([
                    'invitation' => 'An accepted invitation cannot be revoked. Manage the member instead.',
                ]);
            }

            $invitation->revoked_at = now()->toImmutable();
            $invitation->save();
        }));
    }
}
