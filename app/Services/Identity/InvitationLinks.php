<?php

/** Resolve revocable invitation capabilities without depending on browser sessions. */

namespace App\Services\Identity;

use App\Models\TeamInvitation;

/** Treat expired, revoked and rotated capabilities identically. */
final class InvitationLinks
{
    /**
     * @param int $id Public invitation identifier; it grants no authority by itself.
     * @param string $hash Digest proved by the original bearer link and retained in the server-side session.
     * @return TeamInvitation|null Unexpired matching invitation, including an already accepted lifecycle for idempotent POSTs.
     */
    public function resolve(int $id, string $hash): ?TeamInvitation
    {
        $invitation = TeamInvitation::query()->find($id);

        if ($invitation === null) {
            return null;
        }

        if (
            !hash_equals($invitation->token_hash, $hash)
            || $invitation->revoked_at !== null
            || !$invitation->expires_at->isFuture()
        ) {
            return null;
        }

        return $invitation;
    }
}
