<?php

declare(strict_types=1);

/** Exercise monitor mutations, plan limits, pause semantics and lock contention on isolated PostgreSQL. */

use App\Actions\Monitoring\ManageMonitors;
use App\Enums\Billing\Plan;
use App\Enums\Monitoring\MonitorStatus;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamEntitlement;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\MonitorInputFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->freezeTime();
    $this->actions = app(ManageMonitors::class);
});

/**
 * @param Team $team Team whose monitors are read.
 * @return int Number of persisted monitors of that team, paused and suspended ones included.
 */
function monitorCount(Team $team): int
{
    return app(TenantContext::class)->run($team->id, fn (): int => Monitor::query()->count());
}

/**
 * @param Team $team Owning team.
 * @param int $count Monitors to create through the factory, bypassing quota for setup.
 * @param array<string, mixed> $attributes Extra persisted attributes.
 * @return list<Monitor> Created monitors in creation order.
 */
function seedMonitors(Team $team, int $count, array $attributes = []): array
{
    return app(TenantContext::class)->run($team->id, fn (): array => Monitor::factory()->count($count)->create($attributes)->all());
}

/**
 * @param Team $team Owning team.
 * @param int $id Monitor identity.
 * @return Monitor Fresh persisted monitor.
 */
function reloadMonitor(Team $team, int $id): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->findOrFail($id));
}

/**
 * @param Closure(): mixed $operation Action expected to fail validation.
 * @return list<string> Error keys raised.
 */
function validationKeys(Closure $operation): array
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    throw new LogicException('The operation passed validation.');
}

it('resolves the effective plan from the stored plan and the exclusive trial boundary', function (): void {
    $entitlement = app(TeamEntitlement::class);
    $free = Team::factory()->free()->create();
    $pro = Team::factory()->pro()->trialEnded()->create();
    $business = Team::factory()->business()->create();

    expect($entitlement->effectivePlan($free))->toBe(Plan::Pro)
        ->and($entitlement->effectivePlan($free, $free->trial_ends_at->subSecond()))->toBe(Plan::Pro)
        ->and($entitlement->effectivePlan($free, $free->trial_ends_at))->toBe(Plan::Free)
        ->and($entitlement->effectivePlan($pro))->toBe(Plan::Pro)
        ->and($entitlement->effectivePlan($business))->toBe(Plan::Business)
        ->and($entitlement->effectivePlan($business, $business->trial_ends_at->addYear()))->toBe(Plan::Business);
});

it('creates every monitor type through the web route for managing roles', function (array $payload, string $role): void {
    $team = Team::factory()->create();
    $actor = $role === 'owner' ? $team->owner : Membership::factory()->for($team)->{$role}()->create()->user;

    $this->actingAs($actor)->from('/teams/'.$team->id)
        ->post("/teams/{$team->id}/monitors", $payload)
        ->assertRedirect('/teams/'.$team->id)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'monitor-created');

    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->sole());

    expect($monitor->type->value)->toBe($payload['type'])
        ->and($monitor->team_id)->toBe($team->id)
        ->and($monitor->last_status)->toBe(MonitorStatus::Unknown)
        ->and($monitor->next_check_at)->toBeNull()
        ->and($monitor->is_paused)->toBeFalse()
        ->and($monitor->is_plan_suspended)->toBeFalse();
})->with(MonitorInputFixture::cases())->with(['owner', 'admin', 'member']);

it('keeps viewers and revoked members out even when the action is called directly', function (): void {
    $team = Team::factory()->create();
    $viewer = Membership::factory()->for($team)->viewer()->create()->user;
    $member = Membership::factory()->for($team)->member()->create()->user;
    [$monitor] = seedMonitors($team, 1);
    $team->users()->detach($member->id);

    expect(fn () => $this->actions->create($viewer, $team->id, MonitorInputFixture::http()))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->actions->pause($viewer, $team->id, $monitor->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->actions->delete($member, $team->id, $monitor->id))->toThrow(ModelNotFoundException::class)
        ->and(monitorCount($team))->toBe(1)
        ->and(reloadMonitor($team, $monitor->id)->is_paused)->toBeFalse();
});

it('refuses monitors of another team behind an accessible team identity', function (): void {
    $team = Team::factory()->create();
    $foreign = Team::factory()->create();
    [$foreignMonitor] = seedMonitors($foreign, 1);

    expect(fn () => $this->actions->update($team->owner, $team->id, $foreignMonitor->id, ['name' => 'forged']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->actions->delete($team->owner, $team->id, $foreignMonitor->id))->toThrow(ModelNotFoundException::class)
        ->and(reloadMonitor($foreign, $foreignMonitor->id)->name)->toBe('Synthetic monitor');
});

it('rejects forged ownership fields instead of assigning them', function (): void {
    $team = Team::factory()->create();
    $foreign = Team::factory()->create();

    $this->actingAs($team->owner)->from('/teams/'.$team->id)
        ->post("/teams/{$team->id}/monitors", [...MonitorInputFixture::http(), 'team_id' => $foreign->id])
        ->assertSessionHasErrors('team_id');

    expect(monitorCount($team))->toBe(0)->and(monitorCount($foreign))->toBe(0);
});

it('refuses the sixth Free monitor, counting paused and plan-suspended monitors', function (): void {
    $team = Team::factory()->free()->trialEnded()->create();
    seedMonitors($team, 3);
    seedMonitors($team, 1, ['is_paused' => true]);
    seedMonitors($team, 1, ['is_plan_suspended' => true]);

    $this->actingAs($team->owner)->from('/teams/'.$team->id)
        ->post("/teams/{$team->id}/monitors", MonitorInputFixture::http())
        ->assertRedirect('/teams/'.$team->id)
        ->assertSessionHasErrors(['monitor' => 'Your plan allows 5 monitors. Delete a monitor or upgrade to add another.']);

    expect(monitorCount($team))->toBe(5)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('synthetic-secret')->not->toContain('exact body');
});

it('accepts the fifth Free monitor and frees a slot on deletion', function (): void {
    $team = Team::factory()->free()->trialEnded()->create();
    $existing = seedMonitors($team, 4);

    $this->actions->create($team->owner, $team->id, MonitorInputFixture::http());

    expect(validationKeys(fn () => $this->actions->create($team->owner, $team->id, MonitorInputFixture::http())))->toBe(['monitor']);

    $this->actions->delete($team->owner, $team->id, $existing[0]->id);
    $this->actions->create($team->owner, $team->id, MonitorInputFixture::http());

    expect(monitorCount($team))->toBe(5);
});

it('applies the Pro trial quota until the exact trial end', function (): void {
    $team = Team::factory()->free()->create();
    seedMonitors($team, 5);

    $this->actions->create($team->owner, $team->id, MonitorInputFixture::http());

    $this->travelTo($team->trial_ends_at);

    expect(validationKeys(fn () => $this->actions->create($team->owner, $team->id, MonitorInputFixture::http())))->toBe(['monitor'])
        ->and(monitorCount($team))->toBe(6);
});

it('enforces the plan minimum interval on creation and on interval changes', function (Plan $plan, int $refused, int $accepted): void {
    $team = Team::factory()->state(['plan' => $plan])->trialEnded()->create();
    $payload = [...MonitorInputFixture::http(), 'interval_seconds' => $refused];

    expect(validationKeys(fn () => $this->actions->create($team->owner, $team->id, $payload)))->toBe(['interval_seconds']);

    $monitor = $this->actions->create($team->owner, $team->id, [...$payload, 'interval_seconds' => $accepted]);

    expect(validationKeys(fn () => $this->actions->update($team->owner, $team->id, $monitor->id, ['interval_seconds' => $refused])))->toBe(['interval_seconds'])
        ->and(reloadMonitor($team, $monitor->id)->interval_seconds)->toBe($accepted);
})->with([
    'free' => [Plan::Free, 299, 300],
    'pro' => [Plan::Pro, 59, 60],
    'business' => [Plan::Business, 29, 30],
]);

it('allows unrelated edits when an older interval is below a downgraded plan minimum', function (): void {
    $team = Team::factory()->business()->trialEnded()->create();
    $monitor = $this->actions->create($team->owner, $team->id, [...MonitorInputFixture::http(), 'interval_seconds' => 30]);
    $team->forceFill(['plan' => Plan::Free])->save();

    $this->actions->update($team->owner, $team->id, $monitor->id, ['name' => 'Renamed']);

    expect(reloadMonitor($team, $monitor->id)->name)->toBe('Renamed')
        ->and(reloadMonitor($team, $monitor->id)->interval_seconds)->toBe(30);
});

it('preserves omitted secrets and keeps observation state for metadata-only edits', function (): void {
    $team = Team::factory()->create();
    $monitor = $this->actions->create($team->owner, $team->id, MonitorInputFixture::http());
    $slot = CarbonImmutable::now()->addMinute()->startOfSecond();
    DB::table('monitors')->where('id', $monitor->id)->update(['last_status' => 'up', 'consecutive_failures' => 2, 'next_check_at' => $slot]);

    $this->actingAs($team->owner)->from('/teams/'.$team->id)
        ->patch("/teams/{$team->id}/monitors/{$monitor->id}", ['name' => 'Renamed', 'timeout_ms' => 2000])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'monitor-updated');

    $fresh = reloadMonitor($team, $monitor->id);

    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->timeout_ms)->toBe(2000)
        ->and($fresh->url)->toBe('https://example.invalid/Path?key=synthetic-secret')
        ->and($fresh->headers_json)->toBe(['authorization' => ' Bearer synthetic-secret ', 'x.probe' => ''])
        ->and($fresh->body)->toBe(' exact body ')
        ->and($fresh->last_status)->toBe(MonitorStatus::Up)
        ->and($fresh->consecutive_failures)->toBe(2)
        ->and($fresh->next_check_at?->equalTo($slot))->toBeTrue();
});

it('resets the old target observation on configuration changes and the slot on cadence or type changes', function (): void {
    $team = Team::factory()->create();
    $monitor = $this->actions->create($team->owner, $team->id, MonitorInputFixture::http());
    $observed = ['last_status' => 'down', 'consecutive_failures' => 3, 'last_checked_at' => now(), 'next_check_at' => now()->addMinute()];

    DB::table('monitors')->where('id', $monitor->id)->update($observed);
    $this->actions->update($team->owner, $team->id, $monitor->id, ['url' => 'https://other.example.invalid/']);
    $changed = reloadMonitor($team, $monitor->id);

    expect($changed->last_status)->toBe(MonitorStatus::Unknown)
        ->and($changed->consecutive_failures)->toBe(0)
        ->and($changed->last_checked_at)->toBeNull()
        ->and($changed->next_check_at)->not->toBeNull();

    $this->actions->update($team->owner, $team->id, $monitor->id, ['interval_seconds' => 600]);

    expect(reloadMonitor($team, $monitor->id)->next_check_at)->toBeNull();

    DB::table('monitors')->where('id', $monitor->id)->update($observed);
    $this->actions->update($team->owner, $team->id, $monitor->id, ['type' => 'heartbeat', 'heartbeat_grace_seconds' => 30]);
    $retyped = reloadMonitor($team, $monitor->id);

    expect($retyped->type->value)->toBe('heartbeat')
        ->and($retyped->url)->toBeNull()
        ->and($retyped->next_check_at)->toBeNull()
        ->and($retyped->last_status)->toBe(MonitorStatus::Unknown);
});

it('revalidates the explicit patch against the latest locked state instead of an early snapshot', function (): void {
    $team = Team::factory()->create();
    $monitor = $this->actions->create($team->owner, $team->id, MonitorInputFixture::http());

    $this->actions->update($team->owner, $team->id, $monitor->id, ['url' => 'https://newer.example.invalid/']);
    $this->actions->update($team->owner, $team->id, $monitor->id, ['name' => 'Concurrent rename']);

    expect(reloadMonitor($team, $monitor->id)->url)->toBe('https://newer.example.invalid/')
        ->and(validationKeys(fn () => $this->actions->update($team->owner, $team->id, $monitor->id, ['heartbeat_grace_seconds' => 1])))->toBe(['heartbeat_grace_seconds']);
});

it('pauses and resumes idempotently without touching plan suspension', function (): void {
    $team = Team::factory()->create();
    [$monitor] = seedMonitors($team, 1, ['next_check_at' => now()->addMinute()]);

    $this->actingAs($team->owner)->from('/teams/'.$team->id)
        ->post("/teams/{$team->id}/monitors/{$monitor->id}/pause")
        ->assertSessionHas('status', 'monitor-paused');
    $this->actions->pause($team->owner, $team->id, $monitor->id);
    $paused = reloadMonitor($team, $monitor->id);

    expect($paused->is_paused)->toBeTrue()
        ->and($paused->next_check_at)->toBeNull()
        ->and($paused->is_plan_suspended)->toBeFalse();

    $this->actingAs($team->owner)->from('/teams/'.$team->id)
        ->post("/teams/{$team->id}/monitors/{$monitor->id}/resume")
        ->assertSessionHas('status', 'monitor-resumed');
    $this->actions->resume($team->owner, $team->id, $monitor->id);

    expect(reloadMonitor($team, $monitor->id)->is_paused)->toBeFalse();

    DB::table('monitors')->where('id', $monitor->id)->update(['is_paused' => true, 'is_plan_suspended' => true]);
    $this->actions->resume($team->owner, $team->id, $monitor->id);
    $suspended = reloadMonitor($team, $monitor->id);

    expect($suspended->is_paused)->toBeFalse()->and($suspended->is_plan_suspended)->toBeTrue();

    $this->actions->pause($team->owner, $team->id, $monitor->id);

    expect(reloadMonitor($team, $monitor->id)->is_plan_suspended)->toBeTrue();
});

it('resumes only monitors whose deterministic quota position and cadence fit the current plan', function (): void {
    $team = Team::factory()->business()->trialEnded()->create();
    $monitors = seedMonitors($team, 6, ['is_paused' => true]);
    [$fast] = seedMonitors($team, 1, ['is_paused' => true, 'interval_seconds' => 30]);
    $team->forceFill(['plan' => Plan::Free])->save();

    // Frozen time gives every monitor the same created_at, so the ID breaks the tie.
    $this->actions->resume($team->owner, $team->id, $monitors[4]->id);

    expect(reloadMonitor($team, $monitors[4]->id)->is_paused)->toBeFalse()
        ->and(validationKeys(fn () => $this->actions->resume($team->owner, $team->id, $monitors[5]->id)))->toBe(['monitor'])
        ->and(reloadMonitor($team, $monitors[5]->id)->is_paused)->toBeTrue();

    $team->forceFill(['plan' => Plan::Pro])->save();

    expect(validationKeys(fn () => $this->actions->resume($team->owner, $team->id, $fast->id)))->toBe(['interval_seconds']);
});

it('deletes a monitor through the web route', function (): void {
    $team = Team::factory()->create();
    [$monitor] = seedMonitors($team, 1);

    $this->actingAs($team->owner)->from('/teams/'.$team->id)
        ->delete("/teams/{$team->id}/monitors/{$monitor->id}")
        ->assertSessionHas('status', 'monitor-deleted');

    expect(monitorCount($team))->toBe(0);
});

it('serializes competing creations on the Team row before counting the quota', function (): void {
    config(['database.connections.monitor_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('monitor_probe');
    $suffix = (string) Illuminate\Support\Str::uuid();

    $ownerId = $probe->table('users')->insertGetId([
        'name' => 'Quota owner',
        'email' => 'quota-'.$suffix.'@example.test',
        'email_verified_at' => now(),
        'password' => 'unused-test-hash',
    ]);
    $teamId = $probe->table('teams')->insertGetId([
        'name' => 'Quota contention',
        'slug' => 'quota-'.$suffix,
        'owner_id' => $ownerId,
        'plan' => 'free',
        'timezone' => 'UTC',
        'trial_started_at' => now()->subDays(30),
        'trial_ends_at' => now()->subDays(16),
    ]);
    $probe->table('team_user')->insert(['team_id' => $teamId, 'user_id' => $ownerId, 'role' => 'owner']);
    $owner = User::query()->findOrFail($ownerId);

    try {
        DB::setDefaultConnection('monitor_probe');

        foreach (range(1, 4) as $ignored) {
            $this->actions->create($owner, $teamId, MonitorInputFixture::http());
        }

        DB::setDefaultConnection('pgsql');
        DB::beginTransaction();

        try {
            // The fifth creation holds the Team lock in this uncommitted session.
            $this->actions->create($owner, $teamId, MonitorInputFixture::http());

            $probe->statement("SET lock_timeout = '100ms'");
            DB::setDefaultConnection('monitor_probe');

            try {
                $this->actions->create($owner, $teamId, MonitorInputFixture::http());
                $this->fail('The competing creation must wait for the Team lock.');
            } catch (QueryException $exception) {
                expect($exception->errorInfo[0])->toBe('55P03');
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        } finally {
            DB::rollBack();
        }

        // After the competitor's fifth monitor commits, a recount refuses the sixth.
        DB::setDefaultConnection('monitor_probe');
        $probe->statement('SET lock_timeout = 0');
        $this->actions->create($owner, $teamId, MonitorInputFixture::http());

        expect(validationKeys(fn () => $this->actions->create($owner, $teamId, MonitorInputFixture::http())))->toBe(['monitor'])
            ->and($probe->table('monitors')->where('team_id', $teamId)->count())->toBe(5);
    } finally {
        DB::setDefaultConnection('pgsql');
        $probe->table('teams')->where('id', $teamId)->delete();
        $probe->table('users')->where('id', $ownerId)->delete();
        DB::purge('monitor_probe');
    }
});
