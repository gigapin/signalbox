<?php

/** Build disjoint tenant aggregates for authorization matrices and observe persistent side effects. */

namespace Tests\Support;

use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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
     * @param Incident $incident Open local incident of the local monitor.
     * @param Incident $foreignIncident Open incident of the foreign monitor.
     * @param IncidentUpdate $entry Private timeline entry of the local incident.
     * @param IncidentUpdate $foreignEntry Private timeline entry of the foreign incident.
     * @param MaintenanceWindow $window Upcoming window of the local monitor, still editable and deletable.
     * @param MaintenanceWindow $foreignWindow Upcoming window of the foreign monitor.
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
        public Incident $incident,
        public Incident $foreignIncident,
        public IncidentUpdate $entry,
        public IncidentUpdate $foreignEntry,
        public MaintenanceWindow $window,
        public MaintenanceWindow $foreignWindow,
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

        [$incident, $entry, $window] = self::incidentState($team, $monitor);
        [$foreignIncident, $foreignEntry, $foreignWindow] = self::incidentState($foreign, $foreignMonitor);

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
            $incident,
            $foreignIncident,
            $entry,
            $foreignEntry,
            $window,
            $foreignWindow,
        );
    }

    /**
     * @param Team $team Owning team.
     * @param Monitor $monitor Monitor of that team.
     * @return array{Incident, IncidentUpdate, MaintenanceWindow} Open incident with one private entry and an upcoming window starting in a day.
     */
    private static function incidentState(Team $team, Monitor $monitor): array
    {
        return app(TenantContext::class)->run($team->id, function () use ($monitor): array {
            $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
            $entry = IncidentUpdate::factory()->create(['incident_id' => $incident->id]);
            $start = CarbonImmutable::now('UTC')->addDay();

            $window = new MaintenanceWindow();
            $window->forceFill([
                'monitor_id' => $monitor->id,
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'reason' => 'Matrix maintenance',
            ])->save();

            return [$incident, $entry, $window];
        });
    }

    /**
     * @param string $route Named private route with optional member or invitation identifiers.
     * @param bool $foreignTeam Whether to target the disjoint aggregate instead of the actor's team.
     * @param bool $foreignChild Whether to put a foreign member, invitation, monitor, incident, entry or window under the actor's own parents.
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

        // A foreign window or entry is placed under the actor's own monitor or incident: the deepest child is foreign.
        if (in_array($route, ['teams.monitors.maintenance.update', 'teams.monitors.maintenance.destroy'], true)) {
            $parameters['monitor'] = $foreignTeam ? $this->foreignMonitor->id : $this->monitor->id;
            $parameters['maintenanceWindow'] = $foreignTeam || $foreignChild ? $this->foreignWindow->id : $this->window->id;
        }

        if (str_starts_with($route, 'teams.incidents.') && $route !== 'teams.incidents.index') {
            $parameters['incident'] = $foreignTeam || $foreignChild ? $this->foreignIncident->id : $this->incident->id;
        }

        if ($route === 'teams.incidents.updates.publish') {
            $parameters['incident'] = $foreignTeam ? $this->foreignIncident->id : $this->incident->id;
            $parameters['update'] = $foreignTeam || $foreignChild ? $this->foreignEntry->id : $this->entry->id;
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

        if (in_array($route, ['teams.monitors.maintenance.store', 'teams.monitors.maintenance.update'], true)) {
            $start = CarbonImmutable::now('UTC')->addDays(2);

            return [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $start->addHours(2)->toIso8601String(),
                'reason' => 'Matrix planned work',
            ];
        }

        if ($route === 'teams.incidents.updates.store') {
            return ['status' => 'identified', 'message' => 'Matrix update'];
        }

        if ($route === 'teams.incidents.severity') {
            return ['severity' => 'minor'];
        }

        if ($route === 'teams.incidents.root-cause') {
            return ['root_cause_note' => 'Matrix declared cause'];
        }

        if (str_starts_with($route, 'teams.monitors.') || str_starts_with($route, 'teams.incidents.')) {
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

    /** @return Incident Current local incident loaded inside its tenant context. */
    public function loadIncident(): Incident
    {
        return app(TenantContext::class)->run($this->team->id, fn (): Incident => Incident::query()->findOrFail($this->incident->id));
    }

    /** @return list<MaintenanceWindow> Local windows loaded inside their tenant context, in ID order. */
    public function loadWindows(): array
    {
        return app(TenantContext::class)->run($this->team->id, fn (): array => MaintenanceWindow::query()->orderBy('id')->get()->all());
    }

    /** @return TeamInvitation Current local lifecycle loaded only inside its explicit tenant context. */
    public function loadInvitation(): TeamInvitation
    {
        return app(TenantContext::class)->run($this->team->id, fn () => TeamInvitation::query()->findOrFail($this->invitation->id));
    }

    /**
     * Read the test database directly to detect writes even if a scope would hide a changed record.
     *
     * @return array<string, list<array<string, mixed>>> Persistent team, membership, preference, invitation, monitor, incident and maintenance state.
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
            'incidents' => self::rows('incidents', ['*'], ['id']),
            'incident_updates' => self::rows('incident_updates', ['*'], ['id']),
            'maintenance_windows' => self::rows('maintenance_windows', ['*'], ['id']),
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
            'incidents' => array_values(array_filter($snapshot['incidents'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'incident_updates' => array_values(array_filter($snapshot['incident_updates'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'maintenance_windows' => array_values(array_filter($snapshot['maintenance_windows'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
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
