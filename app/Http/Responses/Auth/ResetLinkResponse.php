<?php

/** Give the same reset-link response for known, unknown and recently requested email addresses. */

namespace App\Http\Responses\Auth;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Avoid exposing account existence through reset-link status or validation errors. */
final class ResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    /**
     * @param \Illuminate\Http\Request $request Original request determining JSON or redirect transport.
     * @return Response Uniform public acknowledgement.
     */
    public function toResponse($request): Response
    {
        /** @var string $message Non-enumerating message independent of password broker status. */
        $message = 'If an account exists for that email, a password reset link has been sent.';

        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }
}
