<?php

/** Return password confirmation only to explicitly allowed account or ownership pages. */

namespace App\Http\Responses\Auth;

use App\Models\Team;
use App\Models\User;
use Laravel\Fortify\Contracts\PasswordConfirmedResponse as PasswordConfirmedContract;
use Symfony\Component\HttpFoundation\Response;

/** Keep ownership continuations server-selected while preserving the security-page default. */
final class PasswordConfirmedResponse implements PasswordConfirmedContract
{
    /**
     * @param \Illuminate\Http\Request $request Successfully confirmed password request.
     * @return Response Security-page redirect or JSON acknowledgement.
     */
    public function toResponse($request): Response
    {
        $request->session()->forget('url.intended');

        $teamId = $request->session()->pull('password.return_team');
        $actor = $request->user();

        if ($request->wantsJson()) {
            return response()->json(['confirmed' => true]);
        }

        if ($this->canReturnToOwnership($actor, $teamId)) {
            return redirect()->route('teams.ownership', $teamId);
        }

        return redirect()->route('account.security');
    }

    /**
     * Revalidate a server-selected destination after password confirmation.
     *
     * @param mixed $actor Authenticated identity supplied by the request.
     * @param mixed $teamId Value pulled from the session, never an arbitrary redirect URL.
     * @return bool Whether the actor is still a member and primary owner of that team.
     */
    private function canReturnToOwnership(mixed $actor, mixed $teamId): bool
    {
        if (!$actor instanceof User || !is_int($teamId)) {
            return false;
        }

        if (!$actor->teams()->whereKey($teamId)->exists()) {
            return false;
        }

        return Team::query()
            ->whereKey($teamId)
            ->where('owner_id', $actor->id)
            ->exists();
    }
}
