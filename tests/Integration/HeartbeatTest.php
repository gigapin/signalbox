<?php

declare(strict_types=1);

/** Verify dedicated heartbeat ingress, credential isolation, lifecycle and historical evaluation on PostgreSQL. */

use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Actions\Monitoring\ManageMonitors;
use App\Actions\Monitoring\ReceiveHeartbeat;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use App\Models\Team;
use App\Services\Monitoring\Checks\HeartbeatSnapshotReader;
use App\Services\Monitoring\Checks\HeartbeatStrategy;
use App\Services\Security\SecretRedactor;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL services.');
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    RateLimiter::clear('heartbeat:ip:'.hash('sha256', '127.0.0.1'));
    $this->team = Team::factory()->create();
    $this->actor = $this->team->owner;
    $this->monitor = app(ManageMonitors::class)->create($this->actor, $this->team->id, [
        'name' => 'Synthetic heartbeat', 'type' => 'heartbeat', 'interval_seconds' => 300,
        'timeout_ms' => 1000, 'heartbeat_grace_seconds' => 10,
    ]);
    $this->path = "/teams/{$this->team->id}/monitors/{$this->monitor->id}/heartbeat-credential";
});

/**
 * @param Team $team Previously authorized tenant.
 * @param Monitor $monitor Monitor whose heartbeat state is inspected.
 * @return MonitorHeartbeat Fresh private state inside a bounded tenant context.
 */
function heartbeatState(Team $team, Monitor $monitor): MonitorHeartbeat
{
    return app(TenantContext::class)->run($team->id, fn (): MonitorHeartbeat => MonitorHeartbeat::query()->where('monitor_id', $monitor->id)->sole());
}

it('issues plaintext only once through explicit JSON and stores only its digest', function (): void {
    $response = $this->actingAs($this->actor)->postJson($this->path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $token = $response->json('token');
    $state = heartbeatState($this->team, $this->monitor);
    expect($token)->toMatch('/^[a-f0-9]{64}$/')
        ->and($state->token_hash)->toBe(hash('sha256', $token))
        ->and($state->toArray())->not->toHaveKey('token_hash')
        ->and($response->json('endpoint'))->toBe(route('heartbeats.receive'))
        ->and(session()->all())->not->toContain($token);

    $page = $this->getJson("/teams/{$this->team->id}/monitors/{$this->monitor->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())]);
    $page->assertOk();
    expect($page->getContent())->not->toContain($token, $state->token_hash);
});

it('accepts Bearer-only calls without session or CSRF and ignores client-supplied times and ownership', function (): void {
    $token = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    $this->travel(20)->seconds();
    $this->postJson('/heartbeats', ['received_at' => '2099-01-01', 'team_id' => 999, 'monitor_id' => 999], ['Authorization' => 'Bearer '.$token])
        ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Set-Cookie');

    expect(heartbeatState($this->team, $this->monitor)->last_received_at)->toEqual(CarbonImmutable::now('UTC'))
        ->and(DB::table('heartbeat_receipts')->count())->toBe(1)
        ->and(fn () => app(TenantContext::class)->requireTeamId())->toThrow(\App\Exceptions\MissingTenantContext::class);
});

it('returns the same no-store 404 for absent, malformed and unknown credentials', function (string $case): void {
    $header = match ($case) {
        'unknown' => 'Bearer '.str_repeat('a', 64),
        'malformed' => 'Bearer bad-secret',
        default => '',
    };
    $this->actingAs($this->actor)->postJson('/heartbeats?token=ignored', ['token' => 'ignored'], ['Authorization' => $header])
        ->assertNotFound()->assertExactJson(['message' => 'Not found.'])->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('heartbeat_receipts')->count())->toBe(0);
})->with(['absent', 'malformed', 'unknown']);

it('rotates and revokes immediately without moving the rolling window', function (): void {
    $first = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$first])->assertNoContent();
    $before = heartbeatState($this->team, $this->monitor);
    $this->travel(100)->seconds();
    $second = $this->actingAs($this->actor)->postJson($this->path)->assertOk()->json('token');
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$first])->assertNotFound();
    expect(heartbeatState($this->team, $this->monitor)->last_received_at)->toEqual($before->last_received_at)
        ->and(heartbeatState($this->team, $this->monitor)->generation)->toBe($before->generation);
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$second])->assertNoContent();
    $this->deleteJson($this->path)->assertNoContent();
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$second])->assertNotFound();
});

it('authorizes management only for current managing roles and conceals foreign monitor IDs', function (string $role): void {
    $actor = $role === 'owner' ? $this->actor : Membership::factory()->for($this->team)->{$role}()->create()->user;
    $response = $this->actingAs($actor)->postJson($this->path);
    $role === 'viewer' ? $response->assertForbidden() : $response->assertOk();
    $other = Team::factory()->create();
    $this->postJson("/teams/{$other->id}/monitors/{$this->monitor->id}/heartbeat-credential")->assertNotFound();
})->with(['owner', 'admin', 'member', 'viewer']);

it('acknowledges inactive monitors without recording or clearing their operational state', function (string $case): void {
    $token = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    app(TenantContext::class)->run($this->team->id, function () use ($case): void {
        Monitor::query()->whereKey($this->monitor->id)->update($case === 'paused' ? ['is_paused' => true] : ($case === 'suspended' ? ['is_plan_suspended' => true] : ['interval_seconds' => 30]));
    });
    if ($case === 'cadence') {
        $this->team->forceFill(['plan' => 'free', 'trial_started_at' => now()->subDays(30), 'trial_ends_at' => now()->subDays(16)])->save();
    }
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();
    expect(heartbeatState($this->team, $this->monitor)->last_received_at)->toBeNull()
        ->and(DB::table('heartbeat_receipts')->count())->toBe(0);
})->with(['paused', 'suspended', 'cadence']);

it('rearms real resumes and timing edits but preserves name-only edits and idempotent resumes', function (): void {
    $actions = app(ManageMonitors::class);
    $initial = heartbeatState($this->team, $this->monitor);
    $actions->resume($this->actor, $this->team->id, $this->monitor->id);
    $actions->update($this->actor, $this->team->id, $this->monitor->id, ['name' => 'Renamed']);
    expect(heartbeatState($this->team, $this->monitor)->generation)->toBe(1);
    $actions->pause($this->actor, $this->team->id, $this->monitor->id);
    $this->travel(600)->seconds();
    $actions->resume($this->actor, $this->team->id, $this->monitor->id);
    $resumed = heartbeatState($this->team, $this->monitor);
    expect($resumed->generation)->toBe(2)->and($resumed->started_at)->toEqual($initial->started_at->addSeconds(600));
    $actions->update($this->actor, $this->team->id, $this->monitor->id, ['heartbeat_grace_seconds' => 20]);
    $actions->update($this->actor, $this->team->id, $this->monitor->id, ['interval_seconds' => 600]);
    expect(heartbeatState($this->team, $this->monitor)->generation)->toBe(4);
});

it('does not let a late receipt hide a missed earlier slot and preserves microsecond cutoffs', function (): void {
    $token = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    $start = CarbonImmutable::now('UTC');
    $this->travelTo($start->addSeconds(310)->addMicrosecond());
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();
    $context = new CheckContext($this->team->id, $this->monitor->id, 1, MonitorRegion::Eu, $start->addSeconds(310));
    $snapshot = app(TenantContext::class)->run($this->team->id, fn () => app(HeartbeatSnapshotReader::class)->read($context));
    expect($snapshot->receivedAt)->toBeNull()
        ->and(app(HeartbeatStrategy::class)->evaluate(new CheckInput($context, new HeartbeatMonitorConfiguration(10), 1000), $snapshot, 300)->error)->toBe(CheckError::HeartbeatMissed);
    $later = new CheckContext($this->team->id, $this->monitor->id, 2, MonitorRegion::Us, $start->addSeconds(311));
    $current = app(TenantContext::class)->run($this->team->id, fn () => app(HeartbeatSnapshotReader::class)->read($later));
    expect($current->receivedAt)->toEqual($start->addSeconds(310)->addMicrosecond())
        ->and(app(HeartbeatStrategy::class)->evaluate(new CheckInput($later, new HeartbeatMonitorConfiguration(10), 1000), $current, 300)->error)->toBeNull();
});

it('deduplicates equal-time calls and rolls back receipt and summary writes together', function (): void {
    $token = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    expect(app(ReceiveHeartbeat::class)->receive($token))->toBeTrue()
        ->and(app(ReceiveHeartbeat::class)->receive($token))->toBeTrue()
        ->and(DB::table('heartbeat_receipts')->count())->toBe(1);
    $before = heartbeatState($this->team, $this->monitor)->last_received_at;
    $this->travel(20)->seconds();
    try {
        DB::transaction(function () use ($token): void {
            app(ReceiveHeartbeat::class)->receive($token);
            throw new RuntimeException('Synthetic rollback.');
        });
    } catch (RuntimeException) {
        expect(heartbeatState($this->team, $this->monitor)->last_received_at)->toEqual($before)
            ->and(DB::table('heartbeat_receipts')->count())->toBe(1);
    }
});

it('removes credentials and receipts on type exit or deletion and starts fresh on type reentry', function (): void {
    $token = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    app(ReceiveHeartbeat::class)->receive($token);
    $actions = app(ManageMonitors::class);
    $actions->update($this->actor, $this->team->id, $this->monitor->id, ['type' => 'http', 'url' => 'https://example.invalid']);
    expect(DB::table('monitor_heartbeats')->count())->toBe(0)->and(DB::table('heartbeat_receipts')->count())->toBe(0);
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertNotFound();
    $actions->update($this->actor, $this->team->id, $this->monitor->id, ['type' => 'heartbeat', 'heartbeat_grace_seconds' => 0]);
    expect(heartbeatState($this->team, $this->monitor)->token_hash)->toBeNull();
    $actions->delete($this->actor, $this->team->id, $this->monitor->id);
    expect(DB::table('monitor_heartbeats')->count())->toBe(0);
});

it('throttles IP discovery and valid credentials independently with retry headers', function (): void {
    config(['heartbeat.requests_per_ip_per_minute' => 2, 'heartbeat.requests_per_credential_per_minute' => 1]);
    $token = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertStatus(429)->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson('/heartbeats')->assertStatus(429)->assertHeader('Retry-After');
    expect(DB::table('heartbeat_receipts')->count())->toBe(1);
});

it('redacts credential fields and keeps ingress outside the web middleware stack', function (): void {
    expect(app(SecretRedactor::class)->context(['credential' => 'private', 'token_hash' => 'private', 'Authorization' => 'private']))
        ->toBe(['credential' => '[REDACTED]', 'token_hash' => '[REDACTED]', 'Authorization' => '[REDACTED]']);
    $route = Route::getRoutes()->getByName('heartbeats.receive');
    expect($route->gatherMiddleware())->not->toContain('web', 'auth', 'verified');
    $this->get('/heartbeats')->assertStatus(405);
});

it('acknowledges a monitor beyond its deterministic plan quota without recording a receipt', function (): void {
    $token = app(ManageHeartbeatCredentials::class)->rotate($this->actor, $this->team->id, $this->monitor->id);
    $this->team->forceFill(['plan' => 'free', 'trial_started_at' => now()->subDays(30), 'trial_ends_at' => now()->subDays(16)])->save();
    app(TenantContext::class)->run($this->team->id, fn () => Monitor::factory()->count(5)->create(['created_at' => now()->subMinute()]));
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();
    expect(heartbeatState($this->team, $this->monitor)->last_received_at)->toBeNull()
        ->and(DB::table('heartbeat_receipts')->count())->toBe(0);
});

it('backs existing monitors with a fresh baseline when the heartbeat migration is applied', function (): void {
    $migration = require base_path('database/migrations/2026_10_26_000011_create_monitor_heartbeats.php');
    $migration->down();
    $migration->up();
    $state = heartbeatState($this->team, $this->monitor);
    expect($state->token_hash)->toBeNull()->and($state->last_received_at)->toBeNull()
        ->and($state->generation)->toBe(1)->and($state->started_at->isValid())->toBeTrue()
        ->and(DB::table('heartbeat_receipts')->count())->toBe(0);
});

it('enforces tenant ownership and credential shape independently in PostgreSQL', function (): void {
    $other = Team::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('monitor_heartbeats')->insert([
        'team_id' => $other->id, 'monitor_id' => $this->monitor->id, 'generation' => 1, 'started_at' => now(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('monitor_heartbeats')->where('monitor_id', $this->monitor->id)->update(['token_hash' => 'invalid'])))->toThrow(\Illuminate\Database\QueryException::class);
    $state = heartbeatState($this->team, $this->monitor);
    expect(fn () => DB::transaction(fn () => DB::table('heartbeat_receipts')->insert([
        'team_id' => $other->id, 'monitor_heartbeat_id' => $state->id, 'generation' => 1, 'received_at' => now(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);
    expect(app(TenantContext::class)->run($other->id, fn () => MonitorHeartbeat::query()->where('monitor_id', $this->monitor->id)->first()))->toBeNull();
});

it('refuses invalid traffic-bound configuration instead of disabling rate limits', function (): void {
    config(['heartbeat.requests_per_ip_per_minute' => 0]);
    expect(fn () => app(\App\Services\Monitoring\HeartbeatLimits::class)->get('requests_per_ip_per_minute'))->toThrow(LogicException::class);
});
