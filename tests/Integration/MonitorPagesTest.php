<?php

declare(strict_types=1);

/** Exercise monitor pages, Scout search, filters, pagination, quota hints and secret-free projections on PostgreSQL. */

use App\Actions\Monitoring\ManageMonitors;
use App\Data\Monitoring\MonitorListFilter;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\MonitorListing;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\MonitorInputFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->freezeTime();
});

/**
 * @param Team $team Owning team.
 * @param string $name Monitor label.
 * @param string $variant Factory variant: http, keyword, tls, dns or heartbeat.
 * @param array<string, mixed> $attributes Extra persisted attributes applied after the variant.
 * @return Monitor Persisted synthetic monitor, created outside the quota checks for setup.
 */
function pageMonitor(Team $team, string $name, string $variant = 'http', array $attributes = []): Monitor
{
    return app(TenantContext::class)->run($team->id, function () use ($name, $variant, $attributes): Monitor {
        $monitor = Monitor::factory()->{$variant}()->make(['name' => $name]);
        $monitor->forceFill($attributes)->save();

        return $monitor;
    });
}

/**
 * @param Team $team Team whose list is requested by its owner.
 * @param array<string, string|list<string>> $query Raw query parameters.
 * @return list<string> Monitor names in page order.
 */
function listedNames(Team $team, array $query = []): array
{
    $names = [];

    test()->actingAs($team->owner)
        ->get('/teams/'.$team->id.'/monitors?'.http_build_query($query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$names): void {
            $names = array_column($page->toArray()['props']['monitors']['data'], 'name');
        });

    return $names;
}

it('searches name, TLS host and DNS name case-insensitively but never encrypted URLs', function (): void {
    $team = Team::factory()->create();
    pageMonitor($team, 'Checkout API');
    pageMonitor($team, 'Certificate', 'tls', ['tls_host' => 'shop.example.test']);
    pageMonitor($team, 'Mail records', 'dns', ['dns_name' => 'mail.example.test']);
    pageMonitor($team, 'Unrelated');

    expect(listedNames($team, ['q' => 'checkout']))->toBe(['Checkout API'])
        ->and(listedNames($team, ['q' => 'SHOP.example']))->toBe(['Certificate'])
        ->and(listedNames($team, ['q' => 'mail.EXAMPLE']))->toBe(['Mail records'])
        // Factory HTTP URLs contain "/health", which only exists as ciphertext in the database.
        ->and(listedNames($team, ['q' => 'health']))->toBe([]);
});

it('treats LIKE metacharacters in the search text literally', function (): void {
    $team = Team::factory()->create();
    pageMonitor($team, '100% uptime');
    pageMonitor($team, '1000 uptime');
    pageMonitor($team, 'api_v1');
    pageMonitor($team, 'apixv1');
    pageMonitor($team, 'path\\name');
    pageMonitor($team, 'pathname');

    expect(listedNames($team, ['q' => '%']))->toBe(['100% uptime'])
        ->and(listedNames($team, ['q' => 'api_v1']))->toBe(['api_v1'])
        ->and(listedNames($team, ['q' => 'h\\n']))->toBe(['path\\name'])
        ->and(MonitorListing::likeLiteral('a\\%_'))->toBe('a\\\\\\%\\_');
});

it('never returns another team’s monitors, even with an identical name', function (): void {
    $team = Team::factory()->create();
    $foreign = Team::factory()->create();
    pageMonitor($team, 'Shared name');
    pageMonitor($foreign, 'Shared name');
    pageMonitor($foreign, 'Shared name foreign only');

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors?q=shared")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('monitors.total', 1)
            ->where('usage.used', 1))
        ->assertDontSee('foreign only', false);
});

it('applies an explicit team predicate in addition to TeamScope', function (): void {
    $team = Team::factory()->create();
    $foreign = Team::factory()->create();
    pageMonitor($team, 'Local');
    pageMonitor($foreign, 'Foreign');

    // Context A with a mismatched team B must match nothing: TeamScope alone would return A's rows.
    $page = app(TenantContext::class)->run(
        $team->id,
        fn () => app(MonitorListing::class)->page($foreign->id, new MonitorListFilter()),
    );

    expect($page->total())->toBe(0);
});

it('filters by type, observed status and operational state with suspension precedence', function (): void {
    $team = Team::factory()->create();
    pageMonitor($team, 'Up http', 'http', ['last_status' => 'up']);
    pageMonitor($team, 'Down http', 'http', ['last_status' => 'down']);
    pageMonitor($team, 'Paused tls', 'tls', ['is_paused' => true]);
    pageMonitor($team, 'Suspended dns', 'dns', ['is_plan_suspended' => true]);
    pageMonitor($team, 'Paused and suspended', 'heartbeat', ['is_paused' => true, 'is_plan_suspended' => true]);

    expect(listedNames($team, ['type' => 'http']))->toBe(['Down http', 'Up http'])
        ->and(listedNames($team, ['status' => 'down']))->toBe(['Down http'])
        ->and(listedNames($team, ['status' => 'unknown']))->toBe(['Paused and suspended', 'Paused tls', 'Suspended dns'])
        ->and(listedNames($team, ['state' => 'active']))->toBe(['Down http', 'Up http'])
        ->and(listedNames($team, ['state' => 'paused']))->toBe(['Paused tls'])
        ->and(listedNames($team, ['state' => 'suspended']))->toBe(['Paused and suspended', 'Suspended dns'])
        ->and(listedNames($team, ['type' => 'http', 'status' => 'up', 'q' => 'up']))->toBe(['Up http'])
        ->and(listedNames($team, ['type' => 'tls', 'state' => 'active']))->toBe([]);

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors?state=suspended")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('monitors.data.0.state', 'suspended')
            ->where('filters', ['q' => '', 'type' => null, 'status' => null, 'state' => 'suspended'])
            ->where('filtered', true));
});

it('paginates deterministically by name and then ID', function (): void {
    $team = Team::factory()->business()->create();

    foreach (range(1, 22) as $index) {
        pageMonitor($team, sprintf('Monitor %02d', $index));
    }

    $first = pageMonitor($team, 'Same name');
    $second = pageMonitor($team, 'Same name');

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('monitors.data', 20)
            ->where('monitors.currentPage', 1)
            ->where('monitors.lastPage', 2)
            ->where('monitors.total', 24)
            ->where('monitors.from', 1)
            ->where('monitors.to', 20)
            ->where('monitors.data.0.name', 'Monitor 01')
            ->missing('monitors.links'));

    $this->get("/teams/{$team->id}/monitors?page=2")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('monitors.data', 4)
            ->where('monitors.data.2.id', $first->id)
            ->where('monitors.data.3.id', $second->id));

    $this->get("/teams/{$team->id}/monitors?page=3")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('monitors.data', 0)
            ->where('monitors.currentPage', 3)
            ->where('monitors.from', null));
});

it('ignores and reports invalid filters instead of redirecting', function (): void {
    $team = Team::factory()->create();
    pageMonitor($team, 'Kept');

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors?".http_build_query([
            'q' => str_repeat('a', 101),
            'type' => 'ftp',
            'status' => ['up'],
            'state' => 'active',
            'page' => '0',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rejectedFilters', ['q', 'type', 'status', 'page'])
            ->where('filters', ['q' => '', 'type' => null, 'status' => null, 'state' => 'active'])
            ->has('monitors.data', 1));
});

it('distinguishes an empty team from an empty result', function (): void {
    $team = Team::factory()->create();

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('monitors.data', 0)
            ->where('filtered', false)
            ->where('rejectedFilters', []));

    $this->get("/teams/{$team->id}/monitors?q=missing")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('monitors.data', 0)
            ->where('filtered', true));
});

it('never exposes stored URL secrets, header values, bodies or ciphertext in page props', function (string $route): void {
    $team = Team::factory()->create();
    $monitor = app(ManageMonitors::class)->create($team->owner, $team->id, [...MonitorInputFixture::http(), 'name' => 'Secret holder', 'interval_seconds' => 300]);
    $raw = DB::table('monitors')->where('id', $monitor->id)->first(['url', 'headers_json', 'body']);
    $url = match ($route) {
        'index' => "/teams/{$team->id}/monitors",
        'show' => "/teams/{$team->id}/monitors/{$monitor->id}",
        'edit' => "/teams/{$team->id}/monitors/{$monitor->id}/edit",
    };

    $response = $this->actingAs($team->owner)->get($url)->assertOk();
    $response->assertDontSee('synthetic-secret', false)
        ->assertDontSee('exact body', false)
        ->assertDontSee('Bearer', false)
        ->assertDontSee($raw->url, false)
        ->assertDontSee($raw->headers_json, false)
        ->assertDontSee($raw->body, false)
        ->assertHeader('Cache-Control', 'no-store, private');

    if ($route !== 'index') {
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('monitor.configuration.url', 'https://example.invalid/Path?key=[REDACTED]')
            ->where('monitor.configuration.header_names', ['authorization', 'x.probe'])
            ->where('monitor.configuration.body', ['present' => true, 'bytes' => 12])
            ->missing('monitor.configuration.headers'));
    }
})->with(['index', 'show', 'edit']);

it('projects quota hints from the effective plan and form defaults above the plan minimum', function (): void {
    $free = Team::factory()->trialEnded()->create();
    $trial = Team::factory()->create();
    pageMonitor($free, 'One');
    pageMonitor($free, 'Two');

    $this->actingAs($free->owner)
        ->get("/teams/{$free->id}/monitors/create")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('usage', ['used' => 2, 'limit' => 5, 'minimumIntervalSeconds' => 300, 'full' => false])
            ->where('defaults.interval_seconds', 300)
            ->where('defaults.type', 'http')
            ->has('options.types', 5));

    $this->actingAs($trial->owner)
        ->get("/teams/{$trial->id}/monitors")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('usage', ['used' => 0, 'limit' => 50, 'minimumIntervalSeconds' => 60, 'full' => false]));
});

it('marks a full quota and monitors beyond it without blocking reads', function (): void {
    $team = Team::factory()->trialEnded()->create();

    foreach (range(1, 6) as $index) {
        $last = pageMonitor($team, "Monitor {$index}");
    }

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('usage.full', true)->where('can.create', true));

    $this->get("/teams/{$team->id}/monitors/{$last->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('monitor.withinQuota', false));
});

it('projects management permissions per role', function (string $role): void {
    $team = Team::factory()->create();
    $actor = $role === 'owner' ? $team->owner : Membership::factory()->for($team)->{$role}()->create()->user;
    $monitor = pageMonitor($team, 'Permissions');
    $manages = $role !== 'viewer';

    $this->actingAs($actor)
        ->get("/teams/{$team->id}/monitors/{$monitor->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can', [
            'update' => $manages,
            'delete' => $manages,
            'pause' => $manages,
            'resume' => $manages,
        ]));

    $this->get("/teams/{$team->id}/monitors")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', $manages));
})->with(['owner', 'admin', 'member', 'viewer']);

it('lands on the created monitor with a readable status message', function (): void {
    $team = Team::factory()->create();

    $this->actingAs($team->owner)
        ->from("/teams/{$team->id}/monitors/create")
        ->followingRedirects()
        ->post("/teams/{$team->id}/monitors", [...MonitorInputFixture::http(), 'name' => 'Fresh'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Monitors/Show')
            ->where('monitor.name', 'Fresh')
            ->where('status', 'Monitor created.'));
});
