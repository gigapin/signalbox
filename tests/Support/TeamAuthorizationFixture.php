<?php

/** Build disjoint tenant aggregates for authorization matrices and observe persistent side effects. */

namespace Tests\Support;

use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Notifications\ChannelVerificationCodes;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** All identities and capabilities are synthetic; raw links stay in memory only. */
final readonly class TeamAuthorizationFixture
{
    /** @var string Plaintext of the pending verification code of both fixture channels. */
    public const string PENDING_CODE = '123456';

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
     * @param NotificationChannel $channel Verified local webhook channel with the pending code PENDING_CODE, used by no policy.
     * @param NotificationChannel $foreignChannel Equivalent channel of the foreign team.
     * @param EscalationPolicy $policy Local policy notifying its own separate channel.
     * @param EscalationPolicy $foreignPolicy Policy of the foreign team.
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
        public NotificationChannel $channel,
        public NotificationChannel $foreignChannel,
        public EscalationPolicy $policy,
        public EscalationPolicy $foreignPolicy,
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
        [$channel, $policy] = self::notificationState($team);
        [$foreignChannel, $foreignPolicy] = self::notificationState($foreign);

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
            $channel,
            $foreignChannel,
            $policy,
            $foreignPolicy,
        );
    }

    /**
     * @param Team $team Owning team.
     * @return array{NotificationChannel, EscalationPolicy} Verified webhook channel whose code was sent two minutes ago, and a policy using another channel.
     */
    private static function notificationState(Team $team): array
    {
        return app(TenantContext::class)->run($team->id, function (): array {
            $now = CarbonImmutable::now('UTC');
            $channel = NotificationChannel::factory()->webhook()->active()->create(['name' => 'Matrix channel']);
            $channel->forceFill([
                'verification_code_hash' => app(ChannelVerificationCodes::class)->digest($channel->id, self::PENDING_CODE),
                'verification_expires_at' => $now->addMinutes(13),
                'verification_sent_at' => $now->subMinutes(2),
            ])->save();

            return [$channel, EscalationPolicy::factory()->create(['name' => 'Matrix policy'])];
        });
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
     * @param bool $foreignChild Whether to put a foreign member, invitation, monitor, incident, entry, window, channel or policy under the actor's own parents.
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

        if (str_starts_with($route, 'teams.notification-channels.') && $route !== 'teams.notification-channels.store') {
            $parameters['channel'] = $foreignTeam || $foreignChild ? $this->foreignChannel->id : $this->channel->id;
        }

        if (str_starts_with($route, 'teams.escalation-policies.') && $route !== 'teams.escalation-policies.store') {
            $parameters['policy'] = $foreignTeam || $foreignChild ? $this->foreignPolicy->id : $this->policy->id;
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

        if ($route === 'teams.notification-channels.store') {
            return ['type' => 'email', 'name' => 'Matrix created channel', 'config' => ['address' => 'matrix-channel@example.test'], 'team_id' => $this->foreign->id];
        }

        if ($route === 'teams.notification-channels.update') {
            return ['name' => 'Matrix renamed channel', 'config' => ['url' => '']];
        }

        if ($route === 'teams.notification-channels.verification.confirm') {
            return ['code' => self::PENDING_CODE];
        }

        if ($route === 'teams.notification-channels.activation') {
            return ['active' => false];
        }

        if (in_array($route, ['teams.escalation-policies.store', 'teams.escalation-policies.update'], true)) {
            return [
                'name' => $route === 'teams.escalation-policies.store' ? 'Matrix created policy' : 'Matrix renamed policy',
                'is_default' => true,
                'steps' => [['after_minutes' => 0, 'channel_ids' => [$this->channel->id]]],
                'team_id' => $this->foreign->id,
            ];
        }

        if ($route === 'teams.monitors.escalation-policy') {
            return ['escalation_policy_id' => $this->policy->id];
        }

        if (
            str_starts_with($route, 'teams.monitors.')
            || str_starts_with($route, 'teams.incidents.')
            || str_starts_with($route, 'teams.notification')
            || str_starts_with($route, 'teams.escalation-policies.')
        ) {
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

    /** @return NotificationChannel|null Current local fixture channel loaded inside its tenant context, null once deleted. */
    public function loadChannel(): ?NotificationChannel
    {
        return app(TenantContext::class)->run($this->team->id, fn (): ?NotificationChannel => NotificationChannel::query()->find($this->channel->id));
    }

    /** @return list<EscalationPolicy> Local policies loaded inside their tenant context, in ID order. */
    public function loadPolicies(): array
    {
        return app(TenantContext::class)->run($this->team->id, fn (): array => EscalationPolicy::query()->orderBy('id')->get()->all());
    }

    /** @return TeamInvitation Current local lifecycle loaded only inside its explicit tenant context. */
    public function loadInvitation(): TeamInvitation
    {
        return app(TenantContext::class)->run($this->team->id, fn () => TeamInvitation::query()->findOrFail($this->invitation->id));
    }

    /**
     * Read the test database directly to detect writes even if a scope would hide a changed record.
     *
     * @return array<string, list<array<string, mixed>>> Persistent team, membership, preference, invitation, monitor, incident, maintenance and notification state.
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
            'notification_channels' => self::rows('notification_channels', ['*'], ['id']),
            'escalation_policies' => self::rows('escalation_policies', ['*'], ['id']),
            'notification_logs' => self::rows('notification_logs', ['*'], ['id']),
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
            'notification_channels' => array_values(array_filter($snapshot['notification_channels'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'escalation_policies' => array_values(array_filter($snapshot['escalation_policies'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
            'notification_logs' => array_values(array_filter($snapshot['notification_logs'], fn (array $row): bool => $row['team_id'] === $this->foreign->id)),
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
