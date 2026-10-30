<?php

declare(strict_types=1);

/** Verify public-only runtime connection props and authorized partial page recovery on isolated PostgreSQL. */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '');
    $this->freezeTime();
    Queue::fake();
    $this->team = Team::factory()->create();
    $this->foreign = Team::factory()->create();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'public-browser-key',
        'broadcasting.connections.reverb.secret' => 'server-only-secret',
        'broadcasting.connections.reverb.app_id' => 'server-only-id',
        'broadcasting.connections.reverb.options.host' => 'internal-reverb',
        'broadcasting.browser.host' => null,
        'broadcasting.browser.port' => 18080,
        'broadcasting.browser.scheme' => 'http',
    ]);
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param string $component Exact server component required by Inertia partial matching.
 * @param string $root Selected mutable projection, always read with identity and runtime metadata.
 * @return array<string, string> Existing Inertia protocol headers; no new authorization bypass.
 */
function liveRecoveryHeaders(string $component = 'Monitors/Index', string $root = 'monitors'): array
{
    return [
        'Accept' => 'application/json',
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $root.',team,auth,realtime',
    ];
}

it('shares only public browser coordinates independently of internal publishing configuration', function (): void {
    $this->actingAs($this->team->owner, 'web')
        ->get('http://browser.example.test/teams/'.$this->team->id.'/monitors', liveRecoveryHeaders())
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('props.realtime', ['key' => 'public-browser-key', 'host' => 'browser.example.test', 'port' => 18080, 'scheme' => 'http'])
        ->assertJsonMissingPath('props.options')
        ->assertDontSee('server-only-secret', false)
        ->assertDontSee('server-only-id', false)
        ->assertDontSee('internal-reverb', false);
});

it('uses explicit secure public coordinates and treats a blank host as the current page hostname', function (): void {
    config(['broadcasting.browser.host' => 'socket.example.test', 'broadcasting.browser.scheme' => 'https', 'broadcasting.browser.port' => 443]);
    $this->actingAs($this->team->owner, 'web')
        ->get('https://localhost/teams/'.$this->team->id.'/monitors', liveRecoveryHeaders())
        ->assertOk()->assertJsonPath('props.realtime.host', 'socket.example.test')->assertJsonPath('props.realtime.scheme', 'https');
    config(['broadcasting.browser.host' => '']);
    $this->get('https://localhost/teams/'.$this->team->id.'/monitors', liveRecoveryHeaders())
        ->assertOk()->assertJsonPath('props.realtime.host', 'localhost');
});

it('disables browser connections for offline drivers invalid coordinates or incomplete credentials', function (string $key, mixed $value): void {
    config([$key => $value]);
    $this->actingAs($this->team->owner, 'web')
        ->get('/teams/'.$this->team->id.'/monitors', liveRecoveryHeaders())
        ->assertOk()->assertJsonPath('props.realtime', null);
})->with([
    ['broadcasting.default', 'null'],
    ['broadcasting.default', 'log'],
    ['broadcasting.connections.reverb.key', ''],
    ['broadcasting.connections.reverb.secret', null],
    ['broadcasting.connections.reverb.app_id', '   '],
    ['broadcasting.browser.port', 0],
    ['broadcasting.browser.port', 65536],
    ['broadcasting.browser.scheme', 'ftp'],
]);

it('does not enable mixed-content sockets guests or unverified accounts', function (): void {
    $this->get('/login', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.realtime', null);
    $actor = $this->team->owner;
    $this->actingAs($actor, 'web')
        ->get('https://localhost/teams/'.$this->team->id.'/monitors', liveRecoveryHeaders())
        ->assertOk()->assertJsonPath('props.realtime', null);
    $actor->forceFill(['email_verified_at' => null])->save();
    $this->get('/email/verify', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.realtime', null);
});

it('keeps all partial recovery entrances inside current membership and their normal policies', function (string $role, string $component, string $root): void {
    $actor = $role === 'owner' ? $this->team->owner : User::factory()->create();

    if ($role !== 'owner') {
        $this->team->users()->attach($actor->id, ['role' => $role]);
    }

    $actor->forceFill(['current_team_id' => $this->foreign->id])->save();
    $actor->load('teams');
    [$monitor, $incident] = app(TenantContext::class)->run($this->team->id, function (): array {
        $monitor = Monitor::factory()->create();

        return [$monitor, Incident::factory()->create(['monitor_id' => $monitor->id])];
    });
    $path = match ($component) {
        'Monitors/Index' => '/monitors',
        'Monitors/Show' => '/monitors/'.$monitor->id,
        'Incidents/Index' => '/incidents',
        'Incidents/Show' => '/incidents/'.$incident->id,
        default => throw new LogicException('Unsupported test page.'),
    };
    $headers = liveRecoveryHeaders($component, $root);
    $this->actingAs($actor, 'web')->get('/teams/'.$this->team->id.$path, $headers)
        ->assertOk()->assertJsonPath('component', $component)->assertJsonPath('props.team.id', $this->team->id);
    $this->get('/teams/'.$this->foreign->id.$path, $headers)->assertNotFound();
    $this->team->users()->detach($actor->id);
    $this->get('/teams/'.$this->team->id.$path, $headers)->assertNotFound();
    expect($actor->fresh()?->current_team_id)->toBe($this->foreign->id);
    Queue::assertNothingPushed();
})->with(['owner', 'admin', 'member', 'viewer'])->with([
    ['Monitors/Index', 'monitors'], ['Monitors/Show', 'monitor'], ['Incidents/Index', 'incidents'], ['Incidents/Show', 'incident'],
]);

it('recovers current filtered pagination after missed changes without exposing another team', function (): void {
    app(TenantContext::class)->run($this->team->id, function (): void {
        Monitor::factory()->count(21)->create(['last_status' => 'down']);
        Monitor::factory()->create(['last_status' => 'up']);
    });
    app(TenantContext::class)->run($this->foreign->id, fn () => Monitor::factory()->create(['name' => 'Private foreign monitor', 'last_status' => 'down']));
    $url = '/teams/'.$this->team->id.'/monitors?status=down&page=2';
    $first = $this->actingAs($this->team->owner, 'web')->get($url, liveRecoveryHeaders())
        ->assertOk()->assertJsonPath('props.monitors.total', 21)->assertJsonPath('props.monitors.currentPage', 2)
        ->assertJsonCount(1, 'props.monitors.data')->assertDontSee('Private foreign monitor', false);
    $id = $first->json('props.monitors.data.0.id');
    app(TenantContext::class)->run($this->team->id, fn () => Monitor::query()->findOrFail($id)->delete());
    $this->get($url, liveRecoveryHeaders())
        ->assertOk()->assertJsonPath('props.monitors.total', 20)->assertJsonPath('props.monitors.currentPage', 2)
        ->assertJsonCount(0, 'props.monitors.data')->assertDontSee('Private foreign monitor', false);
});
