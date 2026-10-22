<?php

/** Project accessible teams and delegate membership changes to domain actions. */

namespace App\Http\Controllers\Teams;

use App\Actions\Identity\ManageTeamMembers;
use App\Actions\Identity\SwitchTeam;
use App\Enums\Identity\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\TeamInputRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Identity\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Keep browser orchestration separate from lock-aware domain transitions. */
final class TeamController extends Controller
{
    /** @param TeamAccess $access Shared membership permissions, always resolved from current state. */
    public function __construct(private readonly TeamAccess $access)
    {
    }

    /**
     * List teams reached through the authenticated user's memberships.
     *
     * @param Request $request Verified browser identity.
     * @return Response Accessible teams and the valid current preference.
     */
    public function index(Request $request): Response
    {
        $actor = $this->authenticatedUser($request);
        $teams = $actor->teams()
            ->orderBy('teams.name')
            ->get()
            ->map(fn (Team $team): array => $this->teamSummary($actor, $team));

        return Inertia::render('Teams/Index', [
            'teams' => $teams,
            'currentTeamId' => $actor->resolveCurrentTeam()?->id,
        ]);
    }

    /**
     * Show team members and only the invitations the actor may manage.
     *
     * @param Request $request Verified browser identity.
     * @param int $team Explicit team ID, authorized before private data is loaded.
     * @return Response Member directory and permitted management controls.
     */
    public function show(Request $request, int $team): Response
    {
        $actor = $this->authenticatedUser($request);
        $role = $this->access->membership($actor, $team)->role;
        $identity = Team::query()->findOrFail($team);
        $roles = $this->manageableRoles($role);

        $members = $identity->users()
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $member): array => $this->memberSummary($member, $identity, $role));

        $invitations = TeamInvitation::query()
            ->where('team_id', $team)
            ->whereNull('accepted_at')
            ->whereIn('role', $roles)
            ->orderBy('id')
            ->get()
            ->map(fn (TeamInvitation $invitation): array => $this->invitationSummary($invitation));

        return Inertia::render('Teams/Show', [
            'team' => [
                'id' => $identity->id,
                'name' => $identity->name,
                'ownerId' => $identity->owner_id,
            ],
            'actorId' => $actor->id,
            'roles' => $roles,
            'members' => $members,
            'invitations' => $invitations,
        ]);
    }

    /**
     * Change only the authenticated identity's team preference.
     *
     * @param Request $request Verified preference owner.
     * @param int $team Accessible destination checked by the action.
     * @param SwitchTeam $switch Preference transition coordinated with membership revocation.
     * @return RedirectResponse Account showing the new selection.
     */
    public function select(Request $request, int $team, SwitchTeam $switch): RedirectResponse
    {
        $switch($this->authenticatedUser($request), $team);

        return redirect()->route('account')->with('status', 'team-switched');
    }

    /**
     * Request a role transition within the explicitly selected team.
     *
     * @param TeamInputRequest $request Validated requested role.
     * @param int $team Team whose membership is being changed.
     * @param int $member Target user with an existing membership in that team.
     * @param ManageTeamMembers $members Lock-aware membership transitions.
     * @return RedirectResponse Refreshed management page.
     */
    public function update(
        TeamInputRequest $request,
        int $team,
        int $member,
        ManageTeamMembers $members,
    ): RedirectResponse {
        $members->changeRole(
            $this->authenticatedUser($request),
            $team,
            $member,
            TeamRole::from($request->string('role')->toString()),
        );

        return redirect()->route('teams.show', $team)->with('status', 'member-updated');
    }

    /**
     * Remove a member or leave voluntarily, preserving ownership invariants.
     *
     * @param Request $request Verified manager or departing member.
     * @param int $team Owning team.
     * @param int $member Target membership identity.
     * @param ManageTeamMembers $members Serialized removal and preference cleanup.
     * @return RedirectResponse Team list after departure, or updated member directory.
     */
    public function remove(
        Request $request,
        int $team,
        int $member,
        ManageTeamMembers $members,
    ): RedirectResponse {
        $actor = $this->authenticatedUser($request);
        $members->remove($actor, $team, $member);

        if ($actor->id === $member) {
            return redirect()->route('teams.index')->with('status', 'member-removed');
        }

        return redirect()->route('teams.show', $team)->with('status', 'member-removed');
    }

    /**
     * Require a recent password before exposing eligible ownership successors.
     *
     * @param Request $request Verified primary owner.
     * @param int $team Team whose primary ownership may be transferred.
     * @return Response|RedirectResponse Transfer form or password-confirmation destination.
     */
    public function ownership(Request $request, int $team): Response|RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->access->membership($actor, $team);

        $identity = Team::query()->findOrFail($team);
        abort_unless($identity->owner_id === $actor->id, 403);

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $passwordTimeout = (int) config('auth.password_timeout');

        if (time() - $confirmedAt > $passwordTimeout) {
            $request->session()->put('password.return_team', $team);

            return redirect()->route('password.confirm');
        }

        $owners = $identity->users()
            ->wherePivot('role', TeamRole::Owner->value)
            ->where('users.id', '!=', $actor->id)
            ->get(['users.id', 'users.name'])
            ->map(static fn (User $owner): array => [
                'id' => $owner->id,
                'name' => $owner->name,
            ]);

        return Inertia::render('Teams/Ownership', [
            'team' => ['id' => $team, 'name' => $identity->name],
            'owners' => $owners,
        ]);
    }

    /**
     * Transfer primary ownership after the route's recent-password gate.
     *
     * @param TeamInputRequest $request Validated existing successor identity.
     * @param int $team Owning team.
     * @param ManageTeamMembers $members Serialized transfer with fresh ownership checks.
     * @return RedirectResponse Updated management page.
     */
    public function transfer(
        TeamInputRequest $request,
        int $team,
        ManageTeamMembers $members,
    ): RedirectResponse {
        $members->transfer($this->authenticatedUser($request), $team, $request->integer('user_id'));

        return redirect()->route('teams.show', $team)->with('status', 'ownership-transferred');
    }

    /**
     * Build a list item without serializing a team or its membership model.
     *
     * @param User $actor Current member.
     * @param Team $team Accessible team.
     * @return array{id: int, name: string, role: string} Minimal team identity.
     */
    private function teamSummary(User $actor, Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'role' => $this->access->membership($actor, $team->id)->role->value,
        ];
    }

    /**
     * Enumerate assignable roles in the enum's stable display order.
     *
     * @param TeamRole $actorRole Current persisted actor role.
     * @return list<string> Allowed role values; empty for read-only members.
     */
    private function manageableRoles(TeamRole $actorRole): array
    {
        $roles = [];

        foreach (TeamRole::cases() as $role) {
            if ($this->access->canManage($actorRole, $role)) {
                $roles[] = $role->value;
            }
        }

        return $roles;
    }

    /**
     * Project one member and its presentation permissions.
     *
     * @param User $member Existing team member.
     * @param Team $team Authorized owning team.
     * @param TeamRole $actorRole Actor's current role.
     * @return array{id: int, name: string, email: string, role: string, manageable: bool} Safe member directory item.
     */
    private function memberSummary(User $member, Team $team, TeamRole $actorRole): array
    {
        $memberRole = $this->access->membership($member, $team->id)->role;

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $memberRole->value,
            'manageable' => $member->id !== $team->owner_id
                && $this->access->canManage($actorRole, $memberRole),
        ];
    }

    /**
     * Project an invitation lifecycle without its bearer token or digest.
     *
     * @param TeamInvitation $invitation Invitation the actor is permitted to manage.
     * @return array{id: int, email: string, role: string, expiresAt: string, state: string} Safe management item.
     */
    private function invitationSummary(TeamInvitation $invitation): array
    {
        $state = match (true) {
            $invitation->revoked_at !== null => 'revoked',
            $invitation->isPending() => 'pending',
            default => 'expired',
        };

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'state' => $state,
        ];
    }
}
