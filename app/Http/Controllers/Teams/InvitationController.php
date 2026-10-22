<?php

/** Orchestrate invitation mail and capability-backed browser continuations. */

namespace App\Http\Controllers\Teams;

use App\Actions\Identity\AcceptTeamInvitation;
use App\Actions\Identity\IssueTeamInvitation;
use App\Enums\Identity\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\TeamInputRequest;
use App\Http\Support\InvitationSession;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Identity\InvitationLinks;
use App\Services\Identity\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Keep raw URL credentials out of subsequent page props and redirect destinations. */
final class InvitationController extends Controller
{
    /** @param InvitationSession $context Browser continuation adapter. */
    public function __construct(private readonly InvitationSession $context)
    {
    }

    /**
     * @param Request $request Original bearer-link visit.
     * @param int $invitation Public identifier.
     * @param string $token Raw transient capability.
     * @param InvitationLinks $links Capability verifier.
     * @return RedirectResponse Nonsecret review location, even for invalid links.
     */
    public function open(Request $request, int $invitation, string $token, InvitationLinks $links): RedirectResponse
    {
        $hash = hash('sha256', $token);
        $request->session()->forget('team_invitation');

        if ($links->resolve($invitation, $hash) !== null) {
            $request->session()->put('team_invitation', ['id' => $invitation, 'hash' => $hash]);
        }

        return redirect()->route('invitations.review')->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * @param Request $request Guest, unverified or verified browser.
     * @return Response Minimal capability-proved preview or explicit invalid-link state.
     */
    public function review(Request $request): Response
    {
        $invitation = $this->context->resolve($request);

        if (!$request->user() instanceof User || !$request->user()->hasVerifiedEmail()) {
            $request->session()->put('url.intended', route('invitations.review'));
        }

        $preview = null;

        if ($invitation !== null) {
            $preview = [
                'teamName' => Team::query()->findOrFail($invitation->team_id)->name,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'accepted' => $invitation->accepted_at !== null,
            ];
        }

        $user = $request->user();

        return Inertia::render('Teams/Invitation', [
            'invitation' => $preview,
            'verified' => $user instanceof User && $user->hasVerifiedEmail(),
        ]);
    }

    /**
     * @param Request $request Authenticated account being replaced.
     * @return RedirectResponse Login with the proved invitation continuation retained.
     */
    public function switchAccount(Request $request): RedirectResponse
    {
        $invitation = $this->context->resolve($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($invitation !== null) {
            $request->session()->put('team_invitation', ['id' => $invitation->id, 'hash' => $invitation->token_hash]);
        }

        $request->session()->put('url.intended', route('invitations.review'));

        return redirect()->route('login');
    }

    /**
     * @param Request $request Verified matching identity.
     * @param AcceptTeamInvitation $accept Atomic acceptance action.
     * @return RedirectResponse Joined team or ordinary validation errors.
     */
    public function accept(Request $request, AcceptTeamInvitation $accept): RedirectResponse
    {
        $invitation = $this->context->resolve($request);

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation is unavailable or expired. Request a new link.',
            ]);
        }

        $actor = $this->authenticatedUser($request);

        $team = $accept($actor, $invitation->id, $invitation->token_hash);

        return redirect()->route('teams.show', $team->id)->with('status', 'invitation-accepted');
    }

    /**
     * @param TeamInputRequest $request Validated recipient and role.
     * @param int $team Explicit authorized destination.
     * @param IssueTeamInvitation $issue Lock-aware issuance and post-commit delivery.
     * @return RedirectResponse Saved invitation list.
     */
    public function store(TeamInputRequest $request, int $team, IssueTeamInvitation $issue): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $issue(
            $actor,
            $team,
            $request->string('email')->toString(),
            TeamRole::from($request->string('role')->toString()),
        );

        return redirect()->route('teams.show', $team)->with('status', 'invitation-sent');
    }

    /**
     * @param Request $request Verified manager.
     * @param int $team Explicit owning team.
     * @param int $invitation Team-local invitation ID.
     * @param IssueTeamInvitation $issue Token rotation and delivery.
     * @param TeamAccess $access Check membership before reading recipient information.
     * @return RedirectResponse Updated invitation list.
     */
    public function resend(
        Request $request,
        int $team,
        int $invitation,
        IssueTeamInvitation $issue,
        TeamAccess $access,
    ): RedirectResponse {
        $actor = $this->authenticatedUser($request);
        $access->membership($actor, $team);

        $existing = TeamInvitation::query()->where('team_id', $team)->findOrFail($invitation);
        $issue($actor, $team, $existing->email, $existing->role, $existing->id);

        return redirect()->route('teams.show', $team)->with('status', 'invitation-sent');
    }

    /**
     * @param Request $request Verified manager.
     * @param int $team Explicit owning team.
     * @param int $invitation Team-local invitation ID.
     * @param IssueTeamInvitation $issue Serialized revocation.
     * @return RedirectResponse Updated invitation list.
     */
    public function revoke(Request $request, int $team, int $invitation, IssueTeamInvitation $issue): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $issue->revoke($actor, $team, $invitation);

        return redirect()->route('teams.show', $team)->with('status', 'invitation-revoked');
    }
}
