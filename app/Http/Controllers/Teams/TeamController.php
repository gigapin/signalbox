<?php

/** Project accessible teams and delegate membership changes to domain actions. */

namespace App\Http\Controllers\Teams;

use App\Actions\Identity\ManageTeamMembers;
use App\Actions\Identity\SwitchTeam;
use App\Enums\Identity\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ListTeamsRequest;
use App\Http\Requests\Teams\TeamInputRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\TeamPlanSummary;
use App\Services\Identity\TeamAccess;
use App\Services\Identity\TeamNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Keep browser orchestration separate from lock-aware domain transitions. */
final class TeamController extends Controller
{
    /**
     * @param TeamAccess $access Shared fresh membership permissions.
     * @param TeamNavigation $navigation Safe membership selector projection.
     * @param TeamPlanSummary $plans Member-safe quota projection.
     * @param \App\Services\Tenancy\TenantContext $context Explicit bounded tenant identity.
     */
    public function __construct(private readonly TeamAccess $access, private readonly TeamNavigation $navigation, private readonly TeamPlanSummary $plans, private readonly \App\Services\Tenancy\TenantContext $context)
    {
    }

    /**
     * List teams reached through the authenticated user's memberships.
     *
     * @param ListTeamsRequest $request Validated verified browser identity.
     * @return Response Accessible teams and the valid current preference.
     */
    public function index(ListTeamsRequest $request): Response
    {
        $actor = $this->authenticatedUser($request);
        $navigation = $this->navigation->forUser($actor);

        return Inertia::render('Teams/Index', [
            'teams' => $navigation['teams'],
            'currentTeamId' => $navigation['selectedTeamId'],
            'panel' => $this->panel($request),
        ]);
    }

    /** Read one explicitly authorized panel without changing the account preference.
     * @param Request $request Validated browser query.
     * @return array<string, mixed>|null Minimal selected-team panel.
     */
    private function panel(Request $request): ?array
    {
        $input = $request->validate([
            'team_id' => ['required_with:panel', 'integer', 'min:1'],
            'panel' => ['required_with:team_id', 'in:plan,invite,access'],
        ]);
        if (!isset($input['panel'])) {
            return null;
        }
        $teamId = (int) $input['team_id'];
        $actor = $this->authenticatedUser($request);
        $role = $this->access->membership($actor, $teamId)->role;
        $team = Team::query()->findOrFail($teamId);
        Gate::authorize('view', $team);
        $roles = $this->manageableRoles($role);
        abort_if($input['panel'] === 'invite' && $roles === [], 403);
        return $this->context->run($teamId, fn (): array => [
            'kind' => $input['panel'],
            'team' => ['id' => $teamId, 'name' => $team->name, 'ownerId' => $team->owner_id],
            'actorId' => $actor->id,
            'roles' => $roles,
            'planSummary' => $input['panel'] === 'plan' ? $this->plans->forTeam($team) : null,
        ]);
    }

    /**
     * Show team members and only the invitations the actor may manage.
     *
     * @param ListTeamsRequest $request Validated verified browser identity.
     * @param int $team Explicit team ID, authorized before private data is loaded.
     * @return Response Member directory and permitted management controls.
     */
    public function show(ListTeamsRequest $request, int $team): Response
    {
        $actor = $this->authenticatedUser($request);
        $role = $this->access->membership($actor, $team)->role;
        $identity = Team::query()->findOrFail($team);
        Gate::authorize('view', $identity);
        $roles = $this->manageableRoles($role);

        abort_if($request->routeIs('teams.invitations.index') && $roles === [], 403);

        $members = $identity->users()
            ->orderBy('users.name')->orderBy('users.id')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search->where('users.name', 'ilike', '%'.$request->string('q').'%')->orWhere('users.email', 'ilike', '%'.$request->string('q').'%')))
            ->paginate(10)->withQueryString()
            ->through(fn (User $member): array => $this->memberSummary($member, $identity, $role));

        $invitations = TeamInvitation::query()
            ->where('team_id', $team)
            ->whereNull('accepted_at')
            ->whereIn('role', $roles)
            ->orderBy('id')
            ->when($request->filled('q'), fn ($query) => $query->where('email', 'ilike', '%'.$request->string('q').'%'))
            ->paginate(10)->withQueryString()
            ->through(fn (TeamInvitation $invitation): array => $this->invitationSummary($invitation));

        return Inertia::render($request->routeIs('teams.invitations.index') ? 'Teams/Invitations' : 'Teams/Members', [
            'team' => [
                'id' => $identity->id,
                'name' => $identity->name,
                'ownerId' => $identity->owner_id,
            ],
            'actorId' => $actor->id,
            'roles' => $roles,
            'members' => $members,
            'query' => $request->string('q')->toString(),
            'invitations' => $invitations,
        ]);
    }

    /**
     * Change only the authenticated identity's team preference.
     *
     * @param Request $request Verified preference owner.
     * @param int $team Accessible destination checked by the action.
     * @param SwitchTeam $switch Preference transition coordinated with membership revocation.
     * @return RedirectResponse|JsonResponse Explicit destination dashboard or JSON acknowledgement with the same relative destination.
     */
    public function select(Request $request, int $team, SwitchTeam $switch): RedirectResponse|JsonResponse
    {
        Gate::authorize('view', Team::query()->findOrFail($team));
        $switch($this->authenticatedUser($request), $team);

        $destination = route('teams.dashboard', $team, false);

        if ($request->wantsJson()) {
            return response()->json(['destination' => $destination])
                ->header('Cache-Control', 'no-store, private');
        }

        return redirect()->to($destination)->with('status', 'team-switched');
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
        $actor = $this->authenticatedUser($request);
        $identity = Team::query()->findOrFail($team);
        $target = User::query()->findOrFail($member);
        Gate::authorize('manageRole', [$identity, $this->access->membership($target, $team)->role]);
        Gate::authorize('manageRole', [$identity, TeamRole::from($request->string('role')->toString())]);

        $members->changeRole(
            $actor,
            $team,
            $member,
            TeamRole::from($request->string('role')->toString()),
        );

        return redirect()->route('teams.members.index', ['team' => $team] + $this->listingQuery($request))->with('status', 'member-updated');
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
        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $actor = $this->authenticatedUser($request);
        Gate::authorize('removeMember', [Team::query()->findOrFail($team), User::query()->findOrFail($member)]);
        $members->remove($actor, $team, $member);

        if ($actor->id === $member) {
            return redirect()->route('teams.index')->with('status', 'member-removed');
        }

        return redirect()->route('teams.members.index', ['team' => $team] + $this->listingQuery($request))->with('status', 'member-removed');
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
        Gate::authorize('view', $identity);
        Gate::authorize('transferOwnership', $identity);

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
        Gate::authorize('transferOwnership', Team::query()->findOrFail($team));
        $members->transfer($this->authenticatedUser($request), $team, $request->integer('user_id'));

        return redirect()->route('teams.index')->with('status', 'ownership-transferred');
    }

    /** Retain only validated directory filters after a membership write.
     * @param Request $request Validated directory context, never an arbitrary redirect.
     * @return array<string, int|string> Nonempty search and nondefault page.
     */
    private function listingQuery(Request $request): array
    {
        $query = [];
        if ($request->integer('page', 1) > 1) {
            $query['page'] = $request->integer('page');
        }
        if ($request->filled('q')) {
            $query['q'] = $request->string('q')->toString();
        }
        return $query;
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
