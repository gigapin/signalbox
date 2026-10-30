<?php

declare(strict_types=1);

/** Verify private dashboard aggregates, raw hourly latency semantics and existing partial recovery on PostgreSQL. */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Dashboard\DashboardLatency;
use App\Services\Dashboard\DashboardOverview;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00Z'));
    $this->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '');
    Queue::fake();
    $this->team = Team::factory()->business()->create();
    $this->foreign = Team::factory()->business()->create();
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Explicit owner, independent of selected browser identity.
 * @param array<string, mixed> $attributes Synthetic persisted state, bypassing mutation quotas only for test setup.
 * @return Monitor HTTP monitor whose network configuration must never reach the dashboard.
 */
function dashboardMonitor(Team $team, array $attributes = []): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create($attributes));
}

/**
 * @param Monitor $monitor Owning monitor with a matching tenant.
 * @param CarbonImmutable $slot Immutable scheduled partition key.
 * @param int|null $latency Measured milliseconds; null is an unmeasured observation or absent work.
 * @param string $region Simulated region.
 * @param string $kind Terminal classification.
 * @param string $status Observed regional up/down; ignored for absent work.
 * @param CarbonImmutable|null $recorded Optional recording boundary, normally one second after the slot.
 * @return int Real PostgreSQL ledger identity correlated by the composite result foreign key.
 */
function dashboardResult(Monitor $monitor, CarbonImmutable $slot, ?int $latency, string $region = 'eu', string $kind = 'observed', string $status = 'up', ?CarbonImmutable $recorded = null): int
{
    $recorded ??= $slot->addSecond();
    $id = DB::table('check_executions')->insertGetId([
        'team_id' => $monitor->team_id, 'monitor_id' => $monitor->id, 'region' => $region,
        'slot_at' => $slot->toISOString(), 'expires_at' => $slot->addMinutes(5), 'monitor_revision' => $monitor->execution_revision,
        'state' => $kind === 'observed' ? 'completed' : $kind, 'available_at' => $slot, 'finished_at' => $recorded->toISOString(),
    ]);
    $error = $kind !== 'observed' || $status === 'down';
    DB::table('check_results')->insert([
        'team_id' => $monitor->team_id, 'monitor_id' => $monitor->id, 'execution_id' => $id, 'region' => $region,
        'slot_at' => $slot->toISOString(), 'monitor_revision' => $monitor->execution_revision, 'kind' => $kind,
        'status' => $kind === 'observed' ? $status : 'unknown', 'response_time_ms' => $latency,
        'checked_at' => $kind === 'observed' ? $recorded->toISOString() : null, 'recorded_at' => $recorded->toISOString(),
        'error_code' => $error ? 'synthetic_error' : null, 'error_message' => $error ? 'Synthetic diagnostic.' : null,
    ]);

    return $id;
}

/** @return array<string, string> Existing private Inertia partial-read protocol for dashboard recovery. */
function dashboardHeaders(): array
{
    return ['Accept' => 'application/json', 'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Dashboard/Show', 'X-Inertia-Partial-Data' => 'dashboard,team,auth,realtime'];
}

it('renders honest empty states and nullable uptime without creating a default team or sample', function (): void {
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/dashboard', dashboardHeaders())
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('component', 'Dashboard/Show')
        ->assertJsonPath('props.dashboard.counts.total', 0)
        ->assertJsonPath('props.dashboard.selectedMonitor', null)
        ->assertJsonPath('props.dashboard.uptime.observedPercent', null)
        ->assertJsonPath('props.dashboard.uptime.coveragePercent', null)
        ->assertJsonPath('props.dashboard.uptime.slaPercent', null)
        ->assertJsonCount(24, 'props.dashboard.latency')
        ->assertJsonPath('props.dashboard.latency.0.eu.meanMs', null);
    expect(DB::table('check_results')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('counts disjoint operational states with suspension precedence and isolates every aggregate', function (): void {
    dashboardMonitor($this->team, ['last_status' => 'up']);
    dashboardMonitor($this->team, ['last_status' => 'down']);
    dashboardMonitor($this->team);
    dashboardMonitor($this->team, ['is_paused' => true, 'last_status' => 'up']);
    dashboardMonitor($this->team, ['is_paused' => true, 'is_plan_suspended' => true, 'last_status' => 'down']);
    dashboardMonitor($this->foreign, ['name' => 'Foreign private monitor', 'last_status' => 'down']);
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/dashboard', dashboardHeaders())
        ->assertOk()->assertJsonPath('props.dashboard.counts', ['total' => 5, 'up' => 1, 'down' => 1, 'unknown' => 1, 'paused' => 1, 'suspended' => 1, 'openIncidents' => 0])
        ->assertDontSee('Foreign private monitor', false)
        ->assertJsonMissingPath('props.dashboard.monitors.data.0.configuration')
        ->assertJsonMissingPath('props.dashboard.monitors.data.0.url');
});

it('keeps regions separate and averages measured successes failures and zero while retaining gaps', function (): void {
    $monitor = dashboardMonitor($this->team);
    $slot = CarbonImmutable::now()->subHours(2);
    dashboardResult($monitor, $slot, 0);
    dashboardResult($monitor, $slot->addMinute(), 100, status: 'down');
    dashboardResult($monitor, $slot->addMinutes(2), null);
    dashboardResult($monitor, $slot->addMinutes(3), null, kind: 'missed');
    dashboardResult($monitor, $slot, 400, region: 'us');
    $points = app(TenantContext::class)->run($this->team->id, fn (): array => app(DashboardLatency::class)->series($this->team->id, $monitor->id, CarbonImmutable::now()));
    expect($points)->toHaveCount(24)
        ->and($points[22]['eu'])->toBe(['meanMs' => 50.0, 'samples' => 2])
        ->and($points[22]['us'])->toBe(['meanMs' => 400.0, 'samples' => 1])
        ->and($points[23]['eu'])->toBe(['meanMs' => null, 'samples' => 0]);
});

it('uses half-open UTC slot bounds rejects future recordings and reads both calendar partitions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01T00:30:00Z'));
    $monitor = dashboardMonitor($this->team);
    $end = CarbonImmutable::now();
    $start = $end->subDay();
    dashboardResult($monitor, $start->subSecond(), 999);
    dashboardResult($monitor, $start, 10);
    dashboardResult($monitor, $end->subMinutes(15), 30);
    dashboardResult($monitor, $end, 999);
    dashboardResult($monitor, $end->subMinute(), 999, recorded: $end->addMinute());
    $points = app(TenantContext::class)->run($this->team->id, fn (): array => app(DashboardLatency::class)->series($this->team->id, $monitor->id, $end));
    expect($points[0]['eu'])->toBe(['meanMs' => 10.0, 'samples' => 1])
        ->and($points[23]['eu'])->toBe(['meanMs' => 30.0, 'samples' => 1])
        ->and(array_sum(array_column(array_column($points, 'eu'), 'samples')))->toBe(2);
});

it('never mixes another selected monitor or tenant into raw latency reads even with a mismatched explicit team', function (): void {
    $local = dashboardMonitor($this->team);
    $other = dashboardMonitor($this->team);
    $foreign = dashboardMonitor($this->foreign);
    dashboardResult($local, CarbonImmutable::now()->subHour(), 10);
    dashboardResult($other, CarbonImmutable::now()->subHour(), 999);
    dashboardResult($foreign, CarbonImmutable::now()->subHour(), 999);
    app(TenantContext::class)->run($this->team->id, function () use ($local, $foreign): void {
        $reader = app(DashboardLatency::class);
        expect($reader->series($this->team->id, $local->id, CarbonImmutable::now())[23]['eu']['meanMs'])->toBe(10.0)
            ->and($reader->series($this->foreign->id, $foreign->id, CarbonImmutable::now())[23]['eu']['meanMs'])->toBeNull();
    });
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/dashboard?monitor='.$foreign->id)->assertNotFound();
});

it('shows only five unresolved incidents while counting all open incidents and omitting private notes', function (): void {
    $monitor = dashboardMonitor($this->team);
    app(TenantContext::class)->run($this->team->id, function () use ($monitor): void {
        for ($index = 0; $index < 7; ++$index) {
            $child = $index === 0 ? $monitor : Monitor::factory()->create();
            Incident::factory()->create(['monitor_id' => $child->id, 'root_cause_note' => 'Secret internal cause']);
        }
        Incident::factory()->resolved()->create(['monitor_id' => Monitor::factory()->create()->id]);
    });
    app(TenantContext::class)->run($this->foreign->id, fn () => Incident::factory()->create());
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/dashboard', dashboardHeaders())
        ->assertOk()->assertJsonPath('props.dashboard.counts.openIncidents', 7)
        ->assertJsonCount(5, 'props.dashboard.incidents')->assertDontSee('Secret internal cause', false);
});

it('paginates bounded monitor rows and preserves an explicit selection outside the visible page', function (): void {
    $selected = dashboardMonitor($this->team, ['name' => 'AAA selected']);
    app(TenantContext::class)->run($this->team->id, fn () => Monitor::factory()->count(20)->create(['name' => 'ZZZ monitor']));
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/dashboard?page=2&monitor='.$selected->id, dashboardHeaders())
        ->assertOk()->assertJsonCount(1, 'props.dashboard.monitors.data')
        ->assertJsonPath('props.dashboard.monitors.currentPage', 2)
        ->assertJsonPath('props.dashboard.monitors.total', 21)
        ->assertJsonPath('props.dashboard.selectedMonitor.id', $selected->id);
});

it('reports malformed GET selections without redirect loops or reflected raw payloads', function (array $query, array $rejected): void {
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/dashboard?'.http_build_query($query))
        ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('rejectedFilters', $rejected));
})->with([
    [['monitor' => '0'], ['monitor']],
    [['monitor' => ['synthetic-secret']], ['monitor']],
    [['monitor' => '9223372036854775808'], ['monitor']],
    [['page' => '-1'], ['page']],
    [['page' => '1000001'], ['page']],
]);

it('recovers fresh data at the same private URL and keeps uptime unknown despite observed success', function (): void {
    $monitor = dashboardMonitor($this->team, ['last_status' => 'up']);
    dashboardResult($monitor, CarbonImmutable::now()->subHour(), 123);
    $url = '/teams/'.$this->team->id.'/dashboard?monitor='.$monitor->id;
    $this->actingAs($this->team->owner)->get($url, dashboardHeaders())->assertOk()
        ->assertJsonPath('props.dashboard.latency.23.eu.meanMs', 123)
        ->assertJsonPath('props.dashboard.uptime.observedPercent', null);
    app(TenantContext::class)->run($this->team->id, fn () => $monitor->forceFill(['last_status' => 'down'])->save());
    $this->get($url, dashboardHeaders())->assertOk()->assertJsonPath('props.dashboard.counts.down', 1);
    $this->team->users()->detach($this->team->owner_id);
    $this->get($url, dashboardHeaders())->assertNotFound();
});

it('applies scope to aggregate readers independently of browser middleware', function (): void {
    dashboardMonitor($this->foreign, ['last_status' => 'down']);
    $data = app(TenantContext::class)->run($this->team->id, fn (): array => app(DashboardOverview::class)->read($this->foreign->id, null, 1));
    expect($data['counts']['total'])->toBe(0)->and($data['counts']['openIncidents'])->toBe(0);
});

it('includes a just-completed check within the current second instead of waiting for another event', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00.800000Z'));
    $monitor = dashboardMonitor($this->team);
    dashboardResult($monitor, CarbonImmutable::parse('2026-10-30T12:00:00Z'), 42, recorded: CarbonImmutable::parse('2026-10-30T12:00:00.700000Z'));
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/dashboard', dashboardHeaders())
        ->assertOk()->assertJsonPath('props.dashboard.latency.23.eu.meanMs', 42)
        ->assertJsonPath('props.dashboard.window.endsAt', '2026-10-30T12:00:00.800000Z');
});
