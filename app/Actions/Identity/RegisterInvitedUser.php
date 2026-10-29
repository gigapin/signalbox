<?php

/** Register an invited identity without creating a team or accepting membership. */

namespace App\Actions\Identity;

use App\Models\Team;
use App\Models\User;
use App\Services\Identity\InvitationLinks;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keep registration atomic while preserving explicit verification and acceptance steps. */
final class RegisterInvitedUser
{
    /** @param InvitationLinks $links Resolve the capability from trusted continuation arguments. */
    public function __construct(private readonly InvitationLinks $links)
    {
    }

    /**
     * Create an unverified identity without consuming the invitation or creating a team.
     *
     * @param string $name Validated display name.
     * @param string $email Normalized validated email, required to match the invitation.
     * @param string $password Validated plaintext credential, hashed by User.
     * @param int $invitationId Server-selected invitation identifier.
     * @param string $hash Digest proved by the original invitation link.
     * @return User Unverified identity with no membership, current team or trial yet.
     */
    public function __invoke(string $name, string $email, string $password, int $invitationId, string $hash): User
    {
        return DB::transaction(function () use ($name, $email, $password, $invitationId, $hash): User {
            $invitation = $this->links->resolve($invitationId, $hash);

            if ($invitation !== null) {
                Team::query()->lockForUpdate()->findOrFail($invitation->team_id);
                $invitation = $this->links->resolve($invitationId, $hash);
            }

            if ($invitation === null || !$invitation->isPending() || $invitation->email !== $email) {
                throw ValidationException::withMessages([
                    'invitation' => 'A matching, pending invitation is required. Request a new link if it expired.'
                ]);
            }

            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            DB::afterCommit(static function () use ($user): void {
                event(new Registered($user));
            });

            return $user;
        });
    }
}
