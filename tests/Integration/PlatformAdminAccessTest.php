<?php

declare(strict_types=1);

/** Verify that only platform operators reach the admin panel and Horizon, under every identity state. */

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    // The backend CI job runs without a frontend build, so pages must not need the Vite manifest.
    $this->withoutVite();
});

/** @var array<string, array{method: string, mutation: bool}> ADMIN_ROUTES Complete catalogue of admin routes. */
const ADMIN_ROUTES = [
    'admin.home' => ['method' => 'GET', 'mutation' => false],
    'admin.teams.index' => ['method' => 'GET', 'mutation' => false],
    'admin.teams.show' => ['method' => 'GET', 'mutation' => false],
    'admin.teams.plan-override.update' => ['method' => 'PUT', 'mutation' => true],
    'admin.teams.plan-override.destroy' => ['method' => 'DELETE', 'mutation' => true],
];

/**
 * @param string $name Admin route name.
 * @param Team $team Team used for routes with a team parameter.
 * @return string Relative URL.
 */
function adminUrl(string $name, Team $team): string
{
    return route($name, str_contains($name, 'teams.') && $name !== 'admin.teams.index' ? ['team' => $team->id] : [], absolute: false);
}

/** @return array<string, mixed> Valid override payload, so refusals come from authorization alone. */
function adminPayload(): array
{
    return ['kind' => 'unlimited', 'reason' => 'Matrix probe'];
}

/**
 * @param string $name Admin route name.
 * @param Team $team Target team.
 * @return Illuminate\Testing\TestResponse<Symfony\Component\HttpFoundation\Response> Response with a fresh password confirmation.
 */
function callAdmin(string $name, Team $team): Illuminate\Testing\TestResponse
{
    return test()->withSession(['auth.password_confirmed_at' => time()])
        ->call(ADMIN_ROUTES[$name]['method'], adminUrl($name, $team), adminPayload());
}

it('keeps the admin route catalogue complete and behind the operator middleware', function (): void {
    $registered = [];

    foreach (Route::getRoutes() as $route) {
        if ($route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/')) {
            $registered[] = $route->getName();
        }
    }

    sort($registered);
    $expected = array_keys(ADMIN_ROUTES);
    sort($expected);

    expect($registered)->toBe($expected);

    foreach (ADMIN_ROUTES as $name => $contract) {
        $route = Route::getRoutes()->getByName($name);
        $middleware = $route->gatherMiddleware();

        expect($route->methods())->toContain($contract['method'])
            ->and($middleware)->toContain('auth', 'verified', EnsurePlatformAdmin::class);

        if ($contract['mutation']) {
            expect($middleware)->toContain('password.confirm');
        }
    }
});

it('answers 404 to every team role without changing anything', function (string $name, string $role): void {
    $team = Team::factory()->free()->trialEnded()->create();
    $actor = $role === 'owner' ? $team->owner : Membership::factory()->for($team)->{$role}()->create()->user;
    $before = DB::table('team_plan_overrides')->count() + DB::table('platform_audit_events')->count();

    $this->actingAs($actor);
    callAdmin($name, $team)->assertNotFound()->assertDontSee($team->owner->email, false);

    expect(DB::table('team_plan_overrides')->count() + DB::table('platform_audit_events')->count())->toBe($before);
})->with(array_keys(ADMIN_ROUTES))->with(['owner', 'admin', 'member', 'viewer']);

it('sends guests to login and unverified operators to verification', function (string $name): void {
    $team = Team::factory()->create();

    callAdmin($name, $team)->assertRedirect('/login');

    $this->actingAs(User::factory()->unverified()->platformAdmin()->create());
    expect(callAdmin($name, $team)->isRedirect())->toBeTrue()
        ->and(DB::table('team_plan_overrides')->count())->toBe(0);
})->with(array_keys(ADMIN_ROUTES));

it('admits operators and requires a recent password for changes', function (): void {
    $team = Team::factory()->create();
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get('/admin')->assertRedirect('/admin/teams');
    $this->get('/admin/teams')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get("/admin/teams/{$team->id}")->assertOk();

    $this->put("/admin/teams/{$team->id}/plan-override", adminPayload())->assertRedirect('/user/confirm-password');

    expect(DB::table('team_plan_overrides')->count())->toBe(0);
});

it('applies a revocation on the next request of an existing session', function (): void {
    $operator = User::factory()->platformAdmin()->create();
    $this->actingAs($operator)->get('/admin/teams')->assertOk();

    DB::table('users')->where('id', $operator->id)->update(['is_platform_admin' => false]);

    // The authenticated model still holds the old flag; the gate reads the database.
    $this->get('/admin/teams')->assertNotFound();
    $this->get('/horizon')->assertForbidden();
});

it('requires a confirmed second factor only in production, for the panel and Horizon', function (): void {
    $operator = User::factory()->platformAdmin()->create();
    $this->actingAs($operator);

    $this->get('/admin/teams')->assertOk();
    $this->get('/horizon')->assertOk();

    $this->app['env'] = 'production';

    try {
        $this->get('/admin/teams')
            ->assertRedirect('/account/security')
            ->assertSessionHas('status', 'platform-admin-two-factor-required');
        $this->get('/horizon')->assertForbidden();

        $operator->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->get('/admin/teams')->assertOk();
        $this->get('/horizon')->assertOk();
    } finally {
        $this->app['env'] = 'testing';
    }
});

it('limits Horizon to operators, whatever their team role', function (): void {
    $team = Team::factory()->create();

    $this->actingAs($team->owner)->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->platformAdmin()->create())->get('/horizon')->assertOk();
});

it('shares only the viewer’s own operator flag', function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create())
        ->get('/account')
        ->assertInertia(fn ($page) => $page->where('auth.user.isPlatformAdmin', true));

    $this->actingAs(User::factory()->create())
        ->get('/account')
        ->assertInertia(fn ($page) => $page->where('auth.user.isPlatformAdmin', false));
});
