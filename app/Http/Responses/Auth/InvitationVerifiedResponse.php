<?php

/** Resume capability-backed invitation review after email verification without accepting membership. */

namespace App\Http\Responses\Auth;

use App\Http\Support\InvitationSession;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Laravel\Fortify\Http\Responses\VerifyEmailResponse as DefaultVerifyEmailResponse;
use Symfony\Component\HttpFoundation\Response;

/** Preserve the standard Fortify behavior whenever no valid invitation continuation exists. */
final class InvitationVerifiedResponse implements VerifyEmailResponse
{
    /** @param InvitationSession $invitations Server-owned continuation capability. */
    public function __construct(private readonly InvitationSession $invitations)
    {
    }

    /**
     * @param \Illuminate\Http\Request $request Authenticated identity after signed verification.
     * @return Response Explicit review destination, default account redirect, or normal JSON acknowledgement.
     */
    public function toResponse($request): Response
    {
        if (!$request->wantsJson() && $this->invitations->resolve($request) !== null) {
            $request->session()->forget('url.intended');

            return redirect()->route('invitations.review');
        }

        return (new DefaultVerifyEmailResponse())->toResponse($request);
    }
}
