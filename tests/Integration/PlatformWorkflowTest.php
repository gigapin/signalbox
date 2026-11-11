<?php

declare(strict_types=1);

/** Verify operator navigation and member-safe entitlement snapshots through authorized PostgreSQL routes. */

use App\Actions\Admin\ManagePlanOverrides;
use App\Enums\Billing\Plan;
use App\Enums\Billing\PlanOverrideKind;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamPlanSummary;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
    $this->withoutVite();
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '']);
    $this->freezeTime();
    $this->operator = User::factory()->platformAdmin()->create();
});

it('projects current team plan and exact private usage for all membership roles', function (string $role): void {
    $team = Team::factory()->free()->trialEnded()->create();
    $other = Team::factory()->business()->create();
    $actor = $role === 'owner' ? $team->owner : User::factory()->create();
    if ($role !== 'owner') {
        $team->users()->attach($actor->id, ['role' => $role]);
    }
    app(TenantContext::class)->run($team->id, function (): void {
        Monitor::factory()->create(['is_paused' => true]);
        Monitor::factory()->create(['is_plan_suspended' => true]);
        StatusPage::factory()->create(['is_public' => false]);
    });
    app(TenantContext::class)->run($other->id, fn () => Monitor::factory()->count(3)->create());
    app(ManagePlanOverrides::class)->grant($this->operator, $team->id, PlanOverrideKind::Comped, Plan::Pro, null, 'private-operator-justification');
    $actor->forceFill(['current_team_id' => $other->id])->save();
    $this->actingAs($actor)->get("/teams/{$team->id}")->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('props.planSummary.effectivePlan', 'pro')
        ->assertJsonPath('props.planSummary.source', 'comped_override')
        ->assertJsonPath('props.planSummary.monitors', ['used' => 2, 'limit' => 50])
        ->assertJsonPath('props.planSummary.statusPages', ['used' => 1, 'limit' => 3])
        ->assertJsonMissingPath('props.planSummary.override')
        ->assertDontSee('private-operator-justification', false)
        ->assertDontSee($this->operator->email, false);
    $this->get("/teams/{$other->id}")->assertNotFound();
    $team->users()->detach($actor->id);
    $this->get("/teams/{$team->id}")->assertNotFound();
})->with(['owner', 'admin', 'member', 'viewer']);

it('uses authoritative precedence and exclusive trial or override boundaries', function (string $mode, string $plan, string $source, string $trial): void {
    $team = Team::factory()->free()->trialEnded()->create();
    $now = CarbonImmutable::now('UTC');
    if ($mode === 'trial') {
        $team->forceFill(['trial_ends_at' => $now->addSecond()])->save();
    } elseif ($mode === 'trial-end') {
        $team->forceFill(['trial_ends_at' => $now])->save();
    } elseif ($mode === 'stored') {
        $team->forceFill(['plan' => Plan::Business])->save();
    }
    if (in_array($mode, ['comped', 'unlimited', 'override-end'], true)) {
        app(ManagePlanOverrides::class)->grant(
            $this->operator,
            $team->id,
            $mode === 'unlimited' ? PlanOverrideKind::Unlimited : PlanOverrideKind::Comped,
            $mode === 'unlimited' ? null : Plan::Pro,
            $now->addSecond(),
            'Synthetic private reason'
        );
        if ($mode === 'override-end') {
            $this->travel(1)->seconds();
        }
    }
    $this->actingAs($team->owner)->get("/teams/{$team->id}")
        ->assertOk()->assertJsonPath('props.planSummary.effectivePlan', $plan)
        ->assertJsonPath('props.planSummary.source', $source)
        ->assertJsonPath('props.planSummary.trial.state', $trial);
})->with([
    ['free', 'free', 'free', 'ended'], ['trial', 'pro', 'trial', 'active'],
    ['trial-end', 'free', 'free', 'ended'], ['stored', 'business', 'subscription', 'ended'],
    ['comped', 'pro', 'comped_override', 'ended'], ['unlimited', 'internal', 'unlimited_override', 'ended'],
    ['override-end', 'free', 'free', 'ended'],
]);

it('keeps plan projection query growth constant and omits its work from unrelated partial reads', function (): void {
    $team = Team::factory()->free()->trialEnded()->create();
    $counts = app(TenantContext::class)->run($team->id, function () use ($team): array {
        $counts = [];
        foreach ([1, 40] as $count) {
            Monitor::factory()->count($count === 1 ? 1 : 39)->create();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $summary = app(TeamPlanSummary::class)->forTeam($team);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            expect($summary['monitors']['used'])->toBe($count === 1 ? 1 : 40);
        }

        return $counts;
    });
    expect($counts)->toBe([3, 3]);
    $this->actingAs($team->owner)->get("/teams/{$team->id}", ['X-Inertia-Partial-Component' => 'Teams/Show', 'X-Inertia-Partial-Data' => 'members'])
        ->assertOk()->assertJsonMissingPath('props.planSummary');
});

it('preserves normalized directory context through accepted override writes', function (): void {
    $team = Team::factory()->create();
    $list = '/admin/teams?q=synthetic&page=2';
    $query = http_build_query(['returnTo' => $list]);
    $this->actingAs($this->operator)->get("/admin/teams/{$team->id}?{$query}")
        ->assertOk()->assertJsonPath('props.directoryUrl', $list)
        ->assertJsonPath('props.team.id', $team->id);
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->put("/admin/teams/{$team->id}/plan-override?{$query}", ['kind' => 'unlimited', 'reason' => 'Navigation grant'])
        ->assertSessionHasNoErrors()->assertRedirect("/admin/teams/{$team->id}?{$query}");
    $this->delete("/admin/teams/{$team->id}/plan-override?{$query}", ['reason' => 'Navigation clear'])
        ->assertSessionHasNoErrors()->assertRedirect("/admin/teams/{$team->id}?{$query}");
});

it('rejects unsafe or duplicate operator return hints before projecting links', function (string $mode): void {
    $team = Team::factory()->create();
    $hint = match ($mode) {
        'external' => 'https://evil.test/admin/teams', 'tenant' => '/teams/'.$team->id,
        'detail' => '/admin/teams/'.$team->id, 'array' => ['/admin/teams?q=wrong'],
        'oversized' => '/admin/teams?q='.str_repeat('x', 2100), 'control' => "/admin/teams\n",
        default => '/admin/teams?q=one',
    };
    $query = http_build_query(['returnTo' => $hint]);
    if ($mode === 'duplicate') {
        $query .= '&returnTo='.rawurlencode('/admin/teams?q=two');
    }
    $this->actingAs($this->operator)->get("/admin/teams/{$team->id}?{$query}")
        ->assertOk()->assertJsonPath('props.directoryUrl', '/admin/teams');
})->with(['external', 'tenant', 'detail', 'array', 'oversized', 'control', 'duplicate']);

it('drops invalid bounded directory controls and duplicate inner return fields', function (): void {
    $team = Team::factory()->create();
    $this->actingAs($this->operator)->get('/admin/teams?q[]=bad&page=9999999999999')
        ->assertOk()->assertJsonPath('props.filters.q', '')
        ->assertJsonPath('props.teams.currentPage', 1)
        ->assertJsonPath('props.rejectedFilters', ['q', 'page']);
    $hint = '/admin/teams?q=first&q=second&page=2&unsafe=private';
    $this->get('/admin/teams/'.$team->id.'?'.http_build_query(['returnTo' => $hint]))
        ->assertOk()->assertJsonPath('props.directoryUrl', '/admin/teams?page=2');
});
