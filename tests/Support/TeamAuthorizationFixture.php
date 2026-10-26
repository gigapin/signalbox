<?php

/** Build disjoint tenant aggregates for authorization matrices and observe persistent side effects. */

namespace Tests\Support;

use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** All identities and capabilities are synthetic; raw links stay in memory only. */
final readonly class TeamAuthorizationFixture
{
    /**
     * @param Team $team Actor's initial team.
     * @param Team $foreign Disjoint team with distinct owner and invitation.
     * @param User $actor Account whose role is varied independently of the production policy.
     * @param User $member Local member used for role changes and removal.
     * @param User $successor Existing local owner eligible for primary ownership transfer.
     * @param TeamInvitation $invitation Local member invitation and original rotation digest.
     * @param TeamInvitation $foreignInvitation Invitation inaccessible through the actor's team.
     * @param string $capabilityUrl Local capability URL retained only in memory.
     * @param string $foreignCapabilityUrl Foreign capability used to test ID/token mismatches.
     * @param Monitor $monitor Local monitor targeted by monitor mutation routes.
     * @param Monitor $foreignMonitor Monitor of the disjoint team, never reachable through the actor's team.
     */
    private function __construct(
        public Team $team,
        public Team $foreign,
        public User $actor,
        public User $member,
        public User $successor,
        public TeamInvitation $invitation,
        public TeamInvitation $foreignInvitation,
        public string $capabilityUrl,
        public string $foreignCapabilityUrl,
        public Monitor $monitor,
        public Monitor $foreignMonitor,
    ) {
    }

    /**
     * @param string $role One of the four persisted fixture roles; owner denotes the designated owner.
     * @param bool $heartbeat Whether both monitors need initialized dedicated heartbeat credentials.
     * @return self Independent aggregates, with a selected team and deliberately preloaded memberships.
     */
    public static function create(string $role = 'owner', bool $heartbeat = false): self
    {
        $team = Team::factory()->create(['name' => 'Matrix Alpha']);
        $foreign = Team::factory()->create(['name' => 'Private Beta']);
        $actor = $role === 'owner' ? $team->owner : User::factory()->create();

        if ($role !== 'owner') {
            $team->users()->attach($actor->id, ['role' => $role]);
        }

        $member = User::factory()->create();
        $successor = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member']);
        $team->users()->attach($successor->id, ['role' => 'owner']);
        $actor->forceFill(['current_team_id' => $team->id])->save();
        $actor->load('teams');
        $local = InvitationFixture::issue($team, 'local-matrix@example.test');
        $remote = InvitationFixture::issue($foreign, 'private-beta@example.test');

        $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => ($heartbeat ? Monitor::factory()->heartbeat() : Monitor::factory())->create(['name' => 'Matrix monitor']));
        $foreignMonitor = app(TenantContext::class)->run($foreign->id, fn (): Monitor => ($heartbeat ? Monitor::factory()->heartbeat() : Monitor::factory())->create(['name' => 'Private Beta monitor']));

        if ($heartbeat) {
            app(ManageHeartbeatCredentials::class)->rotate($team->owner, $team->id, $monitor->id);
            app(ManageHeartbeatCredentials::class)->rotate($foreign->owner, $foreign->id, $foreignMonitor->id);
        }

        return new self(
            $team,
            $foreign,
            $actor,
            $member,
            $successor,
            $local['invitation'],
            $remote['invitation'],
            $local['url'],
            $remote['url'],
            $monitor,
            $foreignMonitor,
        );
    }

    /**
     * @param string $route Named private route with optional member or invitation identifiers.
     * @param bool $foreignTeam Whether to target the disjoint aggregate instead of the actor's team.
     * @param bool $foreignChild Whether to put a foreign member/invitation under the actor's team URL.
     * @return string Relative URL using actual persisted identifiers, never guessed fixture IDs.
     */
    public function url(string $route, bool $foreignTeam = false, bool $foreignChild = false): string
    {
        $parameters = ['team' => $foreignTeam ? $this->foreign->id : $this->team->id];

        if (str_contains($route, '.members.')) {
            $parameters['member'] = $foreignTeam || $foreignChild ? $this->foreign->owner_id : $this->member->id;
        }

        if (in_array($route, ['teams.invitations.resend', 'teams.invitations.destroy'], true)) {
            $parameters['invitation'] = $foreignTeam || $foreignChild ? $this->foreignInvitation->id : $this->invitation->id;
        }

        // Collection routes (list, creation form and store) carry no monitor identifier.
        if (str_starts_with($route, 'teams.monitors.') && !in_array($route, ['teams.monitors.index', 'teams.monitors.create', 'teams.monitors.store'], true)) {
            $parameters['monitor'] = $foreignTeam || $foreignChild ? $this->foreignMonitor->id : $this->monitor->id;
        }

        return route($route, $parameters, absolute: false);
    }

    /**
     * @param string $route Named private route receiving valid input for its intended transition.
     * @return array<string, mixed> Valid fields plus forged ownership/actor fields that must never grant authority;
     *                              monitor input rejects unknown fields outright, so it receives only valid fields.
     */
    public function payload(string $route): array
    {
        if ($route === 'teams.monitors.store') {
            return [...MonitorInputFixture::http(), 'name' => 'Matrix created monitor'];
        }

        if ($route === 'teams.monitors.update') {
            return ['name' => 'Matrix renamed monitor'];
        }

        if (str_starts_with($route, 'teams.monitors.')) {
            return [];
        }

        return [
            'role' => $route === 'teams.members.update' ? 'viewer' : 'member',
            'email' => 'new-matrix@example.test',
            'user_id' => $this->successor->id,
            'team_id' => $this->foreign->id,
            'actor_id' => $this->foreign->owner_id,
        ];
    }

    /** @return Monitor|null Current local monitor loaded inside its tenant context, null once deleted. */
    public function loadMonitor(): ?Monitor
    {
        return app(TenantContext::class)->run($this->team->id, fn (): ?Monitor => Monitor::query()->find($this->monitor->id));
    }

    /** @return TeamInvitation Current local lifecycle loaded only inside its explicit tenant context. */
    public function loadInvitation(): TeamInvitation
    {
        return app(TenantContext::class)->run($this->team->id, fn () => TeamInvitation::query()->findOrFail($this->invitation->id));
    }

    /**
     * Read the test database directly to detect writes even if a scope would hide a changed record.
     *
     * @return array<string, list<array<string, mixed>>> Persistent team, membership, preference and invitation state.
     */
    public function snapshot(): array
    {
        return [
            'teams' => self::rows('teams', ['*'], ['id']),
            'memberships' => self::rows('team_user', ['*'], ['team_id', 'user_id']),
            'users' => self::rows('users', ['id', 'current_team_id'], ['id']),
            'invitations' => self::rows('team_invitations', ['*'], ['id']),
            'monitors' => self::rows('monitors', ['*'], ['id']),
            'heartbeats' => self::rows('monitor_heartbeats', ['*'], ['id']),
            'receipts' => self::rows('heartbeat_receipts', ['*'], ['id']),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> Foreign aggregate state that must remain unchanged even after successful local mutations. */
    public function foreignSnapshot(): array
    {
        $snapshot = $this->snapshot();

        return [
            'teams' => array_values(array_filter($snapshot['teams'], fn (array $row): bool => $row['id'] === $this->foreign->id)),
            'memberships' => array_values(array_filter($snapshot['memberships'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'users' => array_values(array_filter($snapshot['users'], fn (array $row): bool => $row['id'] === $this->foreign->owner_id)),
            'invitations' => array_values(array_filter($snapshot['invitations'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'monitors' => array_values(array_filter($snapshot['monitors'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'heartbeats' => array_values(array_filter($snapshot['heartbeats'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'receipts' => array_values(array_filter($snapshot['receipts'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
        ];
    }

    /**
     * @param string $table Test-only relation whose complete persisted state must be compared.
     * @param list<string> $columns Columns needed to observe domain side effects without account credentials.
     * @param list<string> $order Stable unique ordering for deterministic snapshot comparison.
     * @return list<array<string, mixed>> Database rows without retaining framework collection state.
     */
    private static function rows(string $table, array $columns, array $order): array
    {
        $query = DB::table($table);

        foreach ($order as $column) {
            $query->orderBy($column);
        }

        return $query->get($columns)
            ->map(static fn (object $row): array => get_object_vars($row))
            ->all();
    }
}
