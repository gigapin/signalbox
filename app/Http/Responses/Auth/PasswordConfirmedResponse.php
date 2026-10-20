<?php

/** Return recent-password confirmation to the security page rather than a sensitive JSON endpoint. */

namespace App\Http\Responses\Auth;

use Laravel\Fortify\Contracts\PasswordConfirmedResponse as PasswordConfirmedContract;
use Symfony\Component\HttpFoundation\Response;

/** Give password confirmation a single deliberate account destination. */
final class PasswordConfirmedResponse implements PasswordConfirmedContract
{
    /**
     * @param \Illuminate\Http\Request $request Successfully confirmed password request.
     * @return Response Security-page redirect or JSON acknowledgement.
     */
    public function toResponse($request): Response
    {
        $request->session()->forget('url.intended');

        return $request->wantsJson() ? response()->json(['confirmed' => true]) : redirect()->route('account.security');
    }
}
