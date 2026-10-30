<?php

declare(strict_types=1);

/** Connect real PostgreSQL commits, terminal/incident events, Reverb authorization/delivery and private dashboard reads. */

use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\MonitorRegions;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CheckExecution;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\RecordingReverbSocket;
use Tests\Support\ReverbAcceptance;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '');
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00.500000Z'));
    config(['monitor-scheduling.enabled' => true, 'broadcasting.default' => 'reverb']);
    Queue::fake()->serializeAndRestore();
    ReverbAcceptance::isolate();
    $this->fixture = CommittedTeamFixture::freeTeam(1);
    $this->previousTransactions = app('db.transactions');
    $manager = new DatabaseTransactionsManager();
    app()->instance('db.transactions', $manager);
    $this->fixture->connection->setTransactionManager($manager);
    $this->fixture->committed(fn () => app(TenantContext::class)->run($this->fixture->teamId, function (): void {
        Monitor::query()->firstOrFail()->forceFill([
            'name' => 'Acceptance monitor', 'confirmation_threshold' => 1,
            'regions_json' => new MonitorRegions([MonitorRegion::Eu]),
            'url' => 'https://example.test/health?token=canary-network-secret',
            'headers_json' => ['Authorization' => 'Bearer canary-header-secret'],
            'body' => 'canary-body-secret',
        ])->save();
    }));
});

afterEach(function (): void {
    if (isset($this->fixture)) {
        while ($this->fixture->connection->transactionLevel() > 0) {
            $this->fixture->connection->rollBack();
        }

        app()->instance('db.transactions', $this->previousTransactions);
        $this->fixture->cleanup();
    }

    $this->travelBack();
});

/**
 * @param CommittedTeamFixture $fixture Independently committed synthetic tenant, never development data.
 * @param array<string, MonitorStatus> $states Current simulated regional observations.
 * @return list<CheckExecution> Acquired terminal owners usable for duplicate-delivery assertions.
 */
function committedRealtimeStep(CommittedTeamFixture $fixture, array $states): array
{
    $queued = Queue::pushed(BroadcastEvent::class)->count();

    return $fixture->committed(fn (): array => app(TenantContext::class)->run($fixture->teamId, fn (): array => DB::transaction(function () use ($fixture, $states, $queued): array {
        $monitor = Monitor::query()->firstOrFail();
        $monitor->forceFill(['next_check_at' => CarbonImmutable::now()->subSecond()])->save();
        app(ReserveMonitorChecks::class)->run();
        $works = CheckExecution::query()->where('monitor_id', $monitor->id)->where('state', 'pending')->orderBy('region')->get();
        $acquired = [];

        foreach ($works as $work) {
            $owner = app(ExecutionLedger::class)->acquire($fixture->teamId, $work->id);
            expect($owner)->not->toBeNull();
            $status = $states[$work->region];
            $outcome = new CheckOutcome(
                new CheckContext($fixture->teamId, $work->monitor_id, $work->id, MonitorRegion::from($work->region), $work->slot_at),
                $status,
                CarbonImmutable::now('UTC'),
                $work->region === 'eu' ? 123.0 : 456.0,
                $status === MonitorStatus::Up ? 200 : 503,
                $status === MonitorStatus::Down ? CheckError::UnexpectedStatus : null,
            );
            expect(app(ExecutionLedger::class)->finish($owner, outcome: $outcome))->toBeTrue();
            $acquired[] = $owner;
        }

        // No UI frame may escape the real outer transaction before its durable commit.
        expect(Queue::pushed(BroadcastEvent::class)->count())->toBe($queued);

        return $acquired;
    })));
}

/** @return array<string, string> Existing private Inertia dashboard recovery headers. */
function realtimeDashboardHeaders(): array
{
    return ['Accept' => 'application/json', 'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Dashboard/Show', 'X-Inertia-Partial-Data' => 'dashboard,team,auth,realtime'];
}

it('delivers committed check and incident transitions only to a freshly authorized member and recovers their dashboard', function (): void {
    $socket = new RecordingReverbSocket(1);
    $connection = ReverbAcceptance::connection($socket);
    $channel = 'private-team.'.$this->fixture->teamId;
    $auth = $this->actingAs($this->fixture->owner, 'web')->postJson('/broadcasting/auth', [
        'channel_name' => $channel, 'socket_id' => $connection->id(),
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('auth');
    app(EventHandler::class)->subscribe($connection, $channel, $auth);
    $socket->messages = [];
    $url = '/teams/'.$this->fixture->teamId.'/dashboard';

    foreach ([MonitorStatus::Down, MonitorStatus::Up, MonitorStatus::Down] as $index => $status) {
        committedRealtimeStep($this->fixture, ['eu' => $status]);
        $this->get($url, realtimeDashboardHeaders())->assertOk()
            ->assertJsonPath('props.dashboard.counts.'.$status->value, 1)
            ->assertJsonPath('props.dashboard.counts.openIncidents', $status === MonitorStatus::Down ? 1 : 0)
            // Down for five minutes, then up for five: no observed time yet, then 0%, then 50%.
            ->assertJsonPath('props.dashboard.uptime.observedPercent', [null, 0, 50][$index])
            ->assertDontSee('canary-', false);

        if ($index < 2) {
            $this->travel(5)->minutes();
        }
    }

    $jobs = Queue::pushed(BroadcastEvent::class);
    expect($jobs)->toHaveCount(6);
    $names = $jobs->map(fn (BroadcastEvent $job): string => $job->event->broadcastAs())->sort()->values()->all();
    expect($names)->toBe(['CheckCompleted', 'CheckCompleted', 'CheckCompleted', 'IncidentOpened', 'IncidentReopened', 'IncidentResolved']);

    foreach ($jobs as $job) {
        $request = ReverbAcceptance::publication($job);
        expect((string) $request->getBody())->not->toContain('canary-');
        expect(ReverbAcceptance::deliver($request))->toBe(200);
    }

    expect($socket->messages)->toHaveCount(6)
        ->and(DB::table('check_results')->where('team_id', $this->fixture->teamId)->count())->toBe(3)
        ->and(DB::table('incidents')->where('team_id', $this->fixture->teamId)->count())->toBe(1)
        ->and(DB::table('incident_updates')->where('team_id', $this->fixture->teamId)->count())->toBe(3);
    $this->fixture->committed(fn () => DB::table('team_user')->where('team_id', $this->fixture->teamId)->delete());
    $this->postJson('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => $connection->id()])->assertForbidden();
    $this->get($url, realtimeDashboardHeaders())->assertNotFound();
    // Standard private admission does not evict existing sockets; the client closes on the denied recovery read.
    expect(app(ChannelManager::class)->for($connection->app())->find($channel)?->subscribed($connection))->toBeTrue();
});

it('keeps duplicate terminal work from duplicating frames latency samples or incident rows', function (): void {
    $work = committedRealtimeStep($this->fixture, ['eu' => MonitorStatus::Down])[0];
    $before = Queue::pushed(BroadcastEvent::class)->count();
    $duplicate = $this->fixture->committed(fn (): bool => app(ExecutionLedger::class)->finish($work));
    expect($duplicate)->toBeFalse()->and(Queue::pushed(BroadcastEvent::class)->count())->toBe($before);
    $this->actingAs($this->fixture->owner, 'web')->get('/teams/'.$this->fixture->teamId.'/dashboard', realtimeDashboardHeaders())
        ->assertOk()->assertJsonPath('props.dashboard.counts.openIncidents', 1)
        ->assertJsonPath('props.dashboard.latency.23.eu.samples', 1)
        ->assertJsonPath('props.dashboard.latency.23.eu.meanMs', 123);
});

it('preserves regional disagreement through committed facts and the authoritative UI projection', function (): void {
    $this->fixture->committed(fn () => app(TenantContext::class)->run($this->fixture->teamId, fn () => Monitor::query()->firstOrFail()->forceFill([
        'regions_json' => new MonitorRegions([MonitorRegion::Eu, MonitorRegion::Us]),
    ])->save()));
    committedRealtimeStep($this->fixture, ['eu' => MonitorStatus::Up, 'us' => MonitorStatus::Down]);
    $jobs = Queue::pushed(BroadcastEvent::class);
    expect($jobs)->toHaveCount(2)
        ->and($jobs->map(fn (BroadcastEvent $job): string => $job->event->broadcastWith()['status'])->sort()->values()->all())->toBe(['down', 'up']);
    $this->actingAs($this->fixture->owner, 'web')->get('/teams/'.$this->fixture->teamId.'/dashboard', realtimeDashboardHeaders())
        ->assertOk()->assertJsonPath('props.dashboard.counts.unknown', 1)
        ->assertJsonPath('props.dashboard.counts.openIncidents', 0)
        ->assertJsonPath('props.dashboard.latency.23.eu.meanMs', 123)
        ->assertJsonPath('props.dashboard.latency.23.us.meanMs', 456);
});

it('denies a foreign team subscription and dashboard even with a persisted owner and preloaded memberships', function (): void {
    $foreign = Team::factory()->create();
    $actor = $this->fixture->owner;
    $actor->load('teams');
    committedRealtimeStep($this->fixture, ['eu' => MonitorStatus::Up]);
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', [
        'channel_name' => 'private-team.'.$foreign->id, 'socket_id' => '111.222', 'user_id' => $foreign->owner_id,
    ])->assertForbidden()->assertJsonMissingPath('auth');
    $this->get('/teams/'.$foreign->id.'/dashboard', realtimeDashboardHeaders())->assertNotFound();
    expect(Queue::pushed(BroadcastEvent::class)->sole()->event->broadcastOn()[0]->name)->toBe('private-team.'.$this->fixture->teamId);
});
