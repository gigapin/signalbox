<?php

/** Adapt server-side invitation continuation state to transport-independent capability checks. */

namespace App\Http\Support;

use App\Models\TeamInvitation;
use App\Services\Identity\InvitationLinks;
use Illuminate\Http\Request;

/** Retain only ID and digest, never the raw bearer token, through authentication and verification. */
final class InvitationSession
{
    /** @param InvitationLinks $links Fresh database-backed capability resolver. */
    public function __construct(private readonly InvitationLinks $links)
    {
    }

    /**
     * @param Request $request Browser request carrying only server-owned continuation data.
     * @return TeamInvitation|null Fresh valid invitation, or absence after revocation/rotation/expiry.
     */
    public function resolve(Request $request): ?TeamInvitation
    {
        $context = $request->session()->get('team_invitation');

        if (
            !is_array($context)
            || !isset($context['id'], $context['hash'])
            || !is_int($context['id'])
            || !is_string($context['hash'])
        ) {
            return null;
        }

        return $this->links->resolve($context['id'], $context['hash']);
    }
}
