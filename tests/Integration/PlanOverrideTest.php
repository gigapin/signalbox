<?php

declare(strict_types=1);

/** Verify entitlement precedence, operator overrides, their effect on monitor limits and the team directory. */

use App\Actions\Admin\ManagePlanOverrides;
use App\Enums\Billing\EntitlementSource;
use App\Enums\Billing\Plan;
use App\Enums\Billing\PlanOverrideKind;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamEntitlement;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\MonitorInputFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->freezeTime();
    $this->operator = User::factory()->platformAdmin()->create();
});

/**
 * @param Team $team Team receiving the override directly, bypassing HTTP.
 * @param PlanOverrideKind $kind Override kind.
 * @param Plan|null $plan Granted plan for comped overrides.
 * @param CarbonImmutable|null $expiresAt Exclusive end.
 */
function overrideTeam(Team $team, PlanOverrideKind $kind, ?Plan $plan = null, ?CarbonImmutable $expiresAt = null): void
{
    app(ManagePlanOverrides::class)->grant(test()->operator, $team->id, $kind, $plan, $expiresAt, 'Test fixture');
}

/**
 * @param Team $team Owning team.
 * @param int $count Monitors created through the factory, bypassing quota for setup.
 */
function fillMonitors(Team $team, int $count): void
{
    app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->count($count)->create());
}

/**
 * @param Team $team Team whose owner submits a creation through the production route.
 * @param int $interval Requested interval in seconds.
 * @return Illuminate\Testing\TestResponse<Symfony\Component\HttpFoundation\Response> Redirect response.
 */
function ownerCreates(Team $team, int $interval = 300): Illuminate\Testing\TestResponse
{
    return test()->actingAs($team->owner)
        ->from("/teams/{$team->id}/monitors/create")
        ->post("/teams/{$team->id}/monitors", [...MonitorInputFixture::http(), 'interval_seconds' => $interval]);
}

/**
 * @param Team $team Target team.
 * @param array<string, mixed> $payload Override form payload.
 * @return Illuminate\Testing\TestResponse<Symfony\Component\HttpFoundation\Response> Redirect response.
 */
function operatorSaves(Team $team, array $payload): Illuminate\Testing\TestResponse
{
    return test()->actingAs(test()->operator)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->from("/admin/teams/{$team->id}")
        ->put("/admin/teams/{$team->id}/plan-override", $payload);
}

it('resolves overrides before stored plans and trials, with an exclusive end', function (): void {
    $entitlement = app(TeamEntitlement::class);
    $free = Team::factory()->free()->trialEnded()->create();
    $business = Team::factory()->business()->trialEnded()->create();
    $trial = Team::factory()->free()->create();
    // Timestamps are stored at second precision.
    $end = CarbonImmutable::now()->addDays(3)->startOfSecond();

    expect($entitlement->resolve($free)->source)->toBe(EntitlementSource::Free)
        ->and($entitlement->resolve($trial)->source)->toBe(EntitlementSource::Trial);

    overrideTeam($free, PlanOverrideKind::Unlimited, expiresAt: $end);
    overrideTeam($business, PlanOverrideKind::Comped, Plan::Pro);
    overrideTeam($trial, PlanOverrideKind::Comped, Plan::Business);

    $active = $entitlement->resolve($free);

    expect($active->plan)->toBe(Plan::Internal)
        ->and($active->source)->toBe(EntitlementSource::UnlimitedOverride)
        ->and($active->expiresAt?->equalTo($end))->toBeTrue()
        ->and($entitlement->effectivePlan($free, $end->subSecond()))->toBe(Plan::Internal)
        ->and($entitlement->effectivePlan($free, $end))->toBe(Plan::Free)
        // A gift never lowers a paid plan.
        ->and($entitlement->resolve($business)->plan)->toBe(Plan::Business)
        ->and($entitlement->resolve($business)->source)->toBe(EntitlementSource::Subscription)
        ->and($entitlement->resolve($trial)->plan)->toBe(Plan::Business)
        ->and($entitlement->resolve($trial)->source)->toBe(EntitlementSource::CompedOverride);
});

it('never stores the Internal profile or an incoherent override', function (): void {
    $team = Team::factory()->create();

    expect(fn () => DB::table('teams')->where('id', $team->id)->update(['plan' => 'internal']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('team_plan_overrides')->insert([
            'team_id' => $team->id, 'kind' => 'unlimited', 'plan' => 'pro', 'reason' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
});

it('lets an unlimited test team exceed the Free quota and cadence, and restores limits when cleared', function (): void {
    $team = Team::factory()->free()->trialEnded()->create();
    fillMonitors($team, 5);

    ownerCreates($team)->assertSessionHasErrors('monitor');

    operatorSaves($team, ['kind' => 'unlimited', 'reason' => 'QA team'])
        ->assertRedirect("/admin/teams/{$team->id}")
        ->assertSessionHas('status', 'plan-override-saved');

    ownerCreates($team, 30)->assertSessionHasNoErrors();

    $this->actingAs($team->owner)->get("/teams/{$team->id}/monitors")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('usage.limit', 1000)->where('usage.minimumIntervalSeconds', 30));

    $this->actingAs($this->operator)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete("/admin/teams/{$team->id}/plan-override", ['reason' => 'QA finished'])
        ->assertSessionHas('status', 'plan-override-cleared');

    ownerCreates($team)->assertSessionHasErrors('monitor');

    $events = DB::table('platform_audit_events')->orderBy('id')->get();

    expect($events->pluck('action')->all())->toBe(['team.plan_override.granted', 'team.plan_override.cleared'])
        ->and($events->pluck('actor_id')->unique()->all())->toBe([$this->operator->id])
        ->and($events->pluck('reason')->all())->toBe(['QA team', 'QA finished']);
});

it('grants a plan until an inclusive UTC date and falls back afterwards', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
    $team = Team::factory()->free()->trialEnded()->create();
    fillMonitors($team, 5);

    operatorSaves($team, ['kind' => 'comped', 'plan' => 'pro', 'valid_through' => '2026-11-20', 'reason' => 'Customer pilot'])
        ->assertSessionHasNoErrors();

    expect($team->planOverride()->sole()->expires_at?->toIso8601ZuluString())->toBe('2026-11-21T00:00:00Z');

    ownerCreates($team, 60)->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC'));

    ownerCreates($team)->assertSessionHasErrors('monitor');
});

it('writes nothing when the same override is applied again', function (): void {
    $team = Team::factory()->create();

    operatorSaves($team, ['kind' => 'unlimited', 'reason' => 'First']);
    operatorSaves($team, ['kind' => 'unlimited', 'reason' => 'Second']);

    $this->actingAs($this->operator)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete("/admin/teams/{$team->id}/plan-override", ['reason' => 'Done']);
    $this->delete("/admin/teams/{$team->id}/plan-override", ['reason' => 'Again']);

    expect(DB::table('platform_audit_events')->pluck('reason')->all())->toBe(['First', 'Done']);
});

it('rejects incoherent override input without changing anything', function (array $payload, string $field): void {
    $team = Team::factory()->create();

    operatorSaves($team, $payload)->assertSessionHasErrors($field);

    expect(DB::table('team_plan_overrides')->count())->toBe(0)->and(DB::table('platform_audit_events')->count())->toBe(0);
})->with([
    'granted plan without plan' => [['kind' => 'comped', 'reason' => 'x'], 'plan'],
    'granted Internal plan' => [['kind' => 'comped', 'plan' => 'internal', 'reason' => 'x'], 'plan'],
    'unlimited with a plan' => [['kind' => 'unlimited', 'plan' => 'pro', 'reason' => 'x'], 'plan'],
    'unknown kind' => [['kind' => 'forever', 'reason' => 'x'], 'kind'],
    'past date' => [['kind' => 'unlimited', 'valid_through' => '2020-01-01', 'reason' => 'x'], 'valid_through'],
    'malformed date' => [['kind' => 'unlimited', 'valid_through' => '20-11-2026', 'reason' => 'x'], 'valid_through'],
    'missing reason' => [['kind' => 'unlimited'], 'reason'],
    'blank reason' => [['kind' => 'unlimited', 'reason' => '   '], 'reason'],
    'oversized reason' => [['kind' => 'unlimited', 'reason' => str_repeat('x', 501)], 'reason'],
]);

it('re-checks the operator inside the action', function (): void {
    $team = Team::factory()->create();
    DB::table('users')->where('id', $this->operator->id)->update(['is_platform_admin' => false]);

    expect(fn () => overrideTeam($team, PlanOverrideKind::Unlimited))->toThrow(AuthorizationException::class)
        ->and(DB::table('team_plan_overrides')->count())->toBe(0);
});

it('searches teams literally and shows counts but never monitor configuration', function (): void {
    $alpha = Team::factory()->create(['name' => 'Alpha 100% QA']);
    Team::factory()->create(['name' => 'Alpha 1000 QA']);
    fillMonitors($alpha, 2);
    app(TenantContext::class)->run($alpha->id, fn () => app(App\Actions\Monitoring\ManageMonitors::class)->create($alpha->owner, $alpha->id, MonitorInputFixture::http()));

    $this->actingAs($this->operator)
        ->get('/admin/teams?q='.urlencode('100%'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Teams/Index')
            ->where('teams.total', 1)
            ->where('teams.data.0.name', 'Alpha 100% QA')
            ->where('teams.data.0.monitors', 3));

    $this->get('/admin/teams?q='.urlencode($alpha->owner->email))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('teams.data.0.id', $alpha->id));

    $this->get("/admin/teams/{$alpha->id}")
        ->assertOk()
        ->assertDontSee('synthetic-secret', false)
        ->assertDontSee('exact body', false)
        ->assertDontSee('example.invalid', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Teams/Show')
            ->where('team.usage.used', 3)
            ->where('team.entitlement.source', 'trial'));
});

it('makes an override change wait for a creation holding the Team lock', function (): void {
    // The team and operator must be committed so the competing connection can see them.
    $fixture = CommittedTeamFixture::freeTeam(0);
    $operatorId = $fixture->connection->table('users')->insertGetId([
        'name' => 'Probe operator',
        'email' => 'probe-operator-'.Illuminate\Support\Str::uuid().'@example.test',
        'email_verified_at' => now(),
        'password' => 'unused-test-hash',
        'is_platform_admin' => true,
    ]);
    $operator = User::query()->findOrFail($operatorId);

    try {
        DB::beginTransaction();

        try {
            // This session holds the Team row lock, as a monitor creation would.
            DB::table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
            $fixture->connection->statement("SET lock_timeout = '100ms'");

            try {
                $fixture->committed(fn () => app(ManagePlanOverrides::class)->grant($operator, $fixture->teamId, PlanOverrideKind::Unlimited, null, null, 'Contention'));
                $this->fail('The override must wait for the Team lock.');
            } catch (QueryException $exception) {
                expect($exception->errorInfo[0])->toBe('55P03');
            }
        } finally {
            // Rolling back to the savepoint releases the row lock before cleanup.
            DB::rollBack();
        }

        $fixture->connection->statement('SET lock_timeout = 0');

        expect($fixture->connection->table('team_plan_overrides')->where('team_id', $fixture->teamId)->count())->toBe(0);
    } finally {
        $fixture->connection->table('users')->where('id', $operatorId)->delete();
        $fixture->cleanup();
    }
});
