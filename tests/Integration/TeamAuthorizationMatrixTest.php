<?php

/** Exercise every private team route across persisted roles, foreign teams and revoked membership. */

use App\Enums\Identity\TeamRole;
use App\Exceptions\MissingTenantContext;
use App\Http\Middleware\EstablishTeamContext;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Support\TeamAuthorizationFixture;
use Tests\Support\TeamRouteMatrix;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->freezeTime();
    Cache::flush();
    Notification::fake();
});

it('keeps the route catalogue complete and each private verb behind its declared gates', function (): void {
    $registered = [];

    foreach (Route::getRoutes() as $route) {
        $controller = $route->getAction('controller');
        $domainController = is_string($controller)
            && str_starts_with($controller, 'App\\Http\\Controllers\\')
            && !str_starts_with($controller, 'App\\Http\\Controllers\\Auth\\')
            && !str_starts_with($controller, 'App\\Http\\Controllers\\HomeController')
            // Platform admin routes have their own catalogue in PlatformAdminAccessTest.
            && !str_starts_with($controller, 'App\\Http\\Controllers\\Admin\\');

        $tenantUri = str_starts_with($route->uri(), 'teams') || str_starts_with($route->uri(), 'invitations');
        $accountUri = in_array($route->uri(), ['account', 'account/security'], true);
        $tenantMiddleware = in_array(EstablishTeamContext::class, $route->gatherMiddleware(), true);

        if ($domainController || $tenantUri || $accountUri || $tenantMiddleware) {
            $registered[] = $route->getName();
        }
    }

    $expected = [...array_keys(TeamRouteMatrix::PRIVATE_ROUTES), ...array_keys(TeamRouteMatrix::OTHER_ROUTES)];
    sort($registered);
    sort($expected);
    expect($registered)->toBe($expected);

    foreach (TeamRouteMatrix::PRIVATE_ROUTES as $name => $contract) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and(array_values(array_diff($route->methods(), ['HEAD'])))->toBe($contract['methods'] ?? [$contract['method']])
            ->and($route->gatherMiddleware())->toContain('auth', 'verified', EstablishTeamContext::class);
    }

    foreach (TeamRouteMatrix::OTHER_ROUTES as $name => $method) {
        expect(Route::getRoutes()->getByName($name)->methods())->toContain($method);
    }
});

it('enforces route role and membership boundaries without hidden cross-team effects', function (string $name, string $role, string $access): void {
    $fixture = TeamAuthorizationFixture::create($role, heartbeat: str_contains($name, 'heartbeat-credential'));

    if ($access === 'revoked') {
        $fixture->team->users()->detach($fixture->actor->id);
    }

    if ($name === 'teams.select' && $access === 'member') {
        $fixture->actor->forceFill(['current_team_id' => null])->save();
    }

    // Resume is only observable on a paused monitor; both local and foreign targets start paused.
    if ($name === 'teams.monitors.resume') {
        DB::table('monitors')->update(['is_paused' => true]);
    }

    Notification::fake();
    $before = $fixture->snapshot();
    $foreignBefore = $fixture->foreignSnapshot();
    $contract = TeamRouteMatrix::PRIVATE_ROUTES[$name];
    $allowed = $access === 'member' && in_array($role, $contract['roles'], true);
    if ($access !== 'member') {
        $expected = 404;
    } elseif ($allowed) {
        $expected = $contract['status'];
    } else {
        $expected = 403;
    }

    $response = $this->actingAs($fixture->actor)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->call($contract['method'], $fixture->url($name, foreignTeam: $access === 'foreign'), $fixture->payload($name));
    $response->assertStatus($expected)
        ->assertDontSee($fixture->foreign->name, false)
        ->assertDontSee($fixture->foreign->owner->email, false)
        ->assertDontSee($fixture->foreignInvitation->email, false)
        ->assertDontSee($fixture->invitation->token_hash, false)
        ->assertDontSee($fixture->foreignInvitation->token_hash, false);
    expect($fixture->foreignSnapshot())->toBe($foreignBefore);
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);

    if (!$allowed) {
        expect($fixture->snapshot())->toBe($before);
        Notification::assertNothingSent();

        return;
    }

    $response->assertSessionHasNoErrors();

    switch ($name) {
        case 'teams.show':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->where('team.id', $fixture->team->id)
                ->where('actorId', $fixture->actor->id)
                ->has('invitations', in_array($role, ['owner', 'admin'], true) ? 1 : 0)
                ->missing('team.owner.email'));
            break;
        case 'teams.select':
            expect($fixture->actor->fresh()->current_team_id)->toBe($fixture->team->id);
            break;
        case 'teams.members.update':
            expect(Membership::query()->where('team_id', $fixture->team->id)->where('user_id', $fixture->member->id)->sole()->role->value)->toBe('viewer');
            break;
        case 'teams.members.destroy':
            expect($fixture->team->users()->whereKey($fixture->member->id)->exists())->toBeFalse();
            break;
        case 'teams.ownership':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->where('team.id', $fixture->team->id)
                ->has('owners', 1)
                ->where('owners.0.id', $fixture->successor->id));
            break;
        case 'teams.ownership.store':
            expect($fixture->team->fresh()->owner_id)->toBe($fixture->successor->id);
            break;
        case 'teams.invitations.store':
            $created = app(TenantContext::class)->run($fixture->team->id, fn () => TeamInvitation::query()->where('email', 'new-matrix@example.test')->sole());
            expect($created->team_id)->toBe($fixture->team->id)->and($created->role->value)->toBe('member');
            break;
        case 'teams.invitations.resend':
            expect($fixture->loadInvitation()->token_hash)->not->toBe($fixture->invitation->token_hash);
            break;
        case 'teams.invitations.destroy':
            expect($fixture->loadInvitation()->revoked_at)->not->toBeNull();
            break;
        case 'teams.monitors.index':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitors/Index')
                ->where('team.id', $fixture->team->id)
                ->has('monitors.data', 1)
                ->where('monitors.data.0.id', $fixture->monitor->id)
                ->where('can.create', $role !== 'viewer')
                ->missing('monitors.data.0.configuration'));
            break;
        case 'teams.monitors.create':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitors/Create')
                ->where('team.id', $fixture->team->id)
                ->where('usage.used', 1));
            break;
        case 'teams.monitors.show':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitors/Show')
                ->where('monitor.id', $fixture->monitor->id)
                ->where('can.update', $role !== 'viewer')
                ->where('can.delete', $role !== 'viewer'));
            break;
        case 'teams.monitors.edit':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitors/Edit')
                ->where('monitor.id', $fixture->monitor->id));
            break;
        case 'teams.monitors.store':
            $created = app(TenantContext::class)->run($fixture->team->id, fn () => Monitor::query()->where('name', 'Matrix created monitor')->sole());
            expect($created->team_id)->toBe($fixture->team->id);
            break;
        case 'teams.monitors.update':
            expect($fixture->loadMonitor()?->name)->toBe('Matrix renamed monitor');
            break;
        case 'teams.monitors.destroy':
            expect($fixture->loadMonitor())->toBeNull();
            break;
        case 'teams.monitors.pause':
            expect($fixture->loadMonitor()?->is_paused)->toBeTrue();
            break;
        case 'teams.monitors.resume':
            expect($fixture->loadMonitor()?->is_paused)->toBeFalse();
            break;
        case 'teams.monitors.heartbeat-credential.store':
            $digest = DB::table('monitor_heartbeats')->where('monitor_id', $fixture->monitor->id)->value('token_hash');
            expect($digest)->toBe(hash('sha256', $response->json('token')));
            break;
        case 'teams.monitors.heartbeat-credential.destroy':
            expect(DB::table('monitor_heartbeats')->where('monitor_id', $fixture->monitor->id)->value('token_hash'))->toBeNull();
            break;
        case 'teams.incidents.index':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Incidents/Index')
                ->where('team.id', $fixture->team->id)
                ->has('incidents.data', 1)
                ->where('incidents.data.0.id', $fixture->incident->id));
            break;
        case 'teams.incidents.show':
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Incidents/Show')
                ->where('incident.id', $fixture->incident->id)
                ->has('incident.timeline', 1)
                ->where('can.update', $role !== 'viewer'));
            break;
        case 'teams.incidents.acknowledge':
            expect($fixture->loadIncident()->acknowledged_by)->toBe($fixture->actor->id);
            break;
        case 'teams.incidents.updates.store':
            expect($fixture->loadIncident()->status->value)->toBe('identified');
            break;
        case 'teams.incidents.updates.publish':
            expect(DB::table('incident_updates')->where('id', $fixture->entry->id)->value('is_public'))->toBeTrue();
            break;
        case 'teams.incidents.severity':
            expect($fixture->loadIncident()->severity->value)->toBe('minor');
            break;
        case 'teams.incidents.root-cause':
            expect($fixture->loadIncident()->root_cause_note)->toBe('Matrix declared cause');
            break;
        case 'teams.monitors.maintenance.store':
            expect($fixture->loadWindows())->toHaveCount(2)
                ->and($fixture->loadWindows()[1]->reason)->toBe('Matrix planned work');
            break;
        case 'teams.monitors.maintenance.update':
            expect($fixture->loadWindows()[0]->reason)->toBe('Matrix planned work');
            break;
        case 'teams.monitors.maintenance.destroy':
            expect($fixture->loadWindows())->toBe([]);
            break;
    }

    if (in_array($name, ['teams.invitations.store', 'teams.invitations.resend'], true)) {
        Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 1);
    } else {
        Notification::assertNothingSent();
    }
})->with(TeamRouteMatrix::accessCases());

it('gates every private team route before guests or unverified accounts can access data', function (string $name, string $identity): void {
    $fixture = TeamAuthorizationFixture::create();

    if ($identity === 'unverified') {
        $fixture->actor->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($fixture->actor);
    }

    Notification::fake();
    $before = $fixture->snapshot();
    $response = $this->call(TeamRouteMatrix::PRIVATE_ROUTES[$name]['method'], $fixture->url($name), $fixture->payload($name));
    $response->assertRedirect($identity === 'guest' ? '/login' : '/email/verify');
    expect($fixture->snapshot())->toBe($before);
    Notification::assertNothingSent();
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
})->with(TeamRouteMatrix::authenticationCases());

it('rejects foreign child identifiers inside a team the actor owns without changing either aggregate', function (string $name): void {
    $fixture = TeamAuthorizationFixture::create();
    Notification::fake();
    $before = $fixture->snapshot();

    $this->actingAs($fixture->actor)
        ->call(TeamRouteMatrix::PRIVATE_ROUTES[$name]['method'], $fixture->url($name, foreignChild: true), $fixture->payload($name))
        ->assertNotFound();
    expect($fixture->snapshot())->toBe($before);
    Notification::assertNothingSent();
})->with([
    'teams.members.update',
    'teams.members.destroy',
    'teams.invitations.resend',
    'teams.invitations.destroy',
    'teams.monitors.show',
    'teams.monitors.edit',
    'teams.monitors.update',
    'teams.monitors.destroy',
    'teams.monitors.pause',
    'teams.monitors.resume',
    'teams.monitors.heartbeat-credential.store',
    'teams.monitors.heartbeat-credential.destroy',
    'teams.incidents.show',
    'teams.incidents.acknowledge',
    'teams.incidents.updates.store',
    'teams.incidents.updates.publish',
    'teams.incidents.severity',
    'teams.incidents.root-cause',
    'teams.monitors.maintenance.store',
    'teams.monitors.maintenance.update',
    'teams.monitors.maintenance.destroy',
]);

it('rejects a foreign ownership successor despite a forged selected team and fresh password', function (): void {
    $fixture = TeamAuthorizationFixture::create();
    $before = $fixture->snapshot();
    $this->actingAs($fixture->actor)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post($fixture->url('teams.ownership.store'), ['user_id' => $fixture->foreign->owner_id, 'team_id' => $fixture->foreign->id])
        ->assertNotFound();
    expect($fixture->snapshot())->toBe($before);
});

it('projects only accessible teams and never trusts revoked current-team preferences', function (string $role): void {
    $fixture = TeamAuthorizationFixture::create($role);
    $this->actingAs($fixture->actor)->get('/teams')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('teams', 1)
        ->where('teams.0.id', $fixture->team->id)
        ->where('teams.0.role', $role)
        ->where('currentTeamId', $fixture->team->id));
    $this->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('currentTeam.name', $fixture->team->name)
        ->missing('currentTeam.owner_id')
        ->missing('currentTeam.members'));
    $fixture->team->users()->detach($fixture->actor->id);
    $this->get('/teams')->assertInertia(fn (AssertableInertia $page) => $page->has('teams', 0)->where('currentTeamId', null));
    $this->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('currentTeam', null));
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->get('/account/security')->assertOk();
})->with(['owner', 'admin', 'member', 'viewer']);

it('changes the selected team without carrying a previous teams role into the destination', function (): void {
    $fixture = TeamAuthorizationFixture::create();
    $fixture->foreign->users()->attach($fixture->actor->id, ['role' => 'viewer']);
    $this->actingAs($fixture->actor)->post("/teams/{$fixture->foreign->id}/select", ['role' => 'owner'])->assertRedirect('/account');
    expect($fixture->actor->resolveCurrentTeam()?->id)->toBe($fixture->foreign->id);
    Notification::fake();
    $before = $fixture->snapshot();
    $this->post("/teams/{$fixture->foreign->id}/invitations", ['email' => 'forged-manager@example.test', 'role' => 'member'])->assertForbidden();
    expect($fixture->snapshot())->toBe($before);
    Notification::assertNothingSent();
    $this->get("/teams/{$fixture->team->id}")->assertOk();
    expect($fixture->actor->resolveCurrentTeam()?->id)->toBe($fixture->foreign->id);
});

it('does not turn a valid invitation capability into private team access', function (): void {
    $fixture = TeamAuthorizationFixture::create();
    $outsider = User::factory()->create(['email' => $fixture->invitation->email]);
    $this->actingAs($outsider)->get($fixture->capabilityUrl)->assertRedirect('/invitations/accept');
    $this->get('/invitations/accept')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('invitation.teamName', $fixture->team->name)
        ->where('invitation.email', $outsider->email)
        ->missing('invitation.id')
        ->missing('invitation.token_hash')
        ->missing('invitation.members'));
    $this->get("/teams/{$fixture->team->id}")->assertNotFound();
    expect($outsider->resolveCurrentTeam())->toBeNull();
    $this->post('/invitations/accept')->assertRedirect("/teams/{$fixture->team->id}");
    $this->get("/teams/{$fixture->team->id}")->assertOk();
    $this->get("/teams/{$fixture->foreign->id}")->assertNotFound();
});

it('rejects invitation ID and capability-token combinations taken from different teams', function (): void {
    $fixture = TeamAuthorizationFixture::create();
    $foreignToken = basename(parse_url($fixture->foreignCapabilityUrl, PHP_URL_PATH));
    $before = $fixture->snapshot();
    $this->get("/invitations/{$fixture->invitation->id}/{$foreignToken}")->assertRedirect('/invitations/accept');
    $this->get('/invitations/accept')->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
    expect($fixture->snapshot())->toBe($before);
});


it('enforces the complete actor and target role hierarchy for management routes', function (string $name, string $actorRole, string $targetRole): void {
    $fixture = TeamAuthorizationFixture::create($actorRole);
    $fixture->team->users()->updateExistingPivot($fixture->member->id, ['role' => $targetRole]);
    app(TenantContext::class)->run($fixture->team->id, function () use ($fixture, $targetRole): void {
        $fixture->invitation->role = TeamRole::from($targetRole);
        $fixture->invitation->save();
    });
    $payload = $fixture->payload($name);

    if ($name === 'teams.invitations.store') {
        $payload['role'] = $targetRole;
    }

    Notification::fake();
    $before = $fixture->snapshot();
    $foreignBefore = $fixture->foreignSnapshot();
    $allowed = $actorRole === 'owner' || ($actorRole === 'admin' && in_array($targetRole, ['member', 'viewer'], true));
    $response = $this->actingAs($fixture->actor)->call(TeamRouteMatrix::PRIVATE_ROUTES[$name]['method'], $fixture->url($name), $payload);
    $response->assertStatus($allowed ? 302 : 403);
    expect($fixture->foreignSnapshot())->toBe($foreignBefore);

    if (!$allowed) {
        expect($fixture->snapshot())->toBe($before);
        Notification::assertNothingSent();

        return;
    }

    $response->assertSessionHasNoErrors();

    switch ($name) {
        case 'teams.members.update':
            expect(Membership::query()->where('team_id', $fixture->team->id)->where('user_id', $fixture->member->id)->sole()->role->value)->toBe('viewer');
            break;
        case 'teams.members.destroy':
            expect($fixture->team->users()->whereKey($fixture->member->id)->exists())->toBeFalse();
            break;
        case 'teams.invitations.store':
            expect(app(TenantContext::class)->run($fixture->team->id, fn () => TeamInvitation::query()->where('email', 'new-matrix@example.test')->sole()->role->value))->toBe($targetRole);
            break;
        case 'teams.invitations.resend':
            expect($fixture->loadInvitation()->token_hash)->not->toBe($fixture->invitation->token_hash)
                ->and($fixture->loadInvitation()->role->value)->toBe($targetRole);
            break;
        case 'teams.invitations.destroy':
            expect($fixture->loadInvitation()->revoked_at)->not->toBeNull();
            break;
    }
})->with(TeamRouteMatrix::roleCases());

it('checks the requested role as well as the existing membership role', function (string $actorRole, string $requestedRole): void {
    $fixture = TeamAuthorizationFixture::create($actorRole);
    Notification::fake();
    $before = $fixture->snapshot();
    $allowed = $actorRole === 'owner' || ($actorRole === 'admin' && in_array($requestedRole, ['member', 'viewer'], true));
    $response = $this->actingAs($fixture->actor)->patch($fixture->url('teams.members.update'), ['role' => $requestedRole]);
    $response->assertStatus($allowed ? 302 : 403);

    if ($allowed) {
        $response->assertSessionHasNoErrors();
        expect(Membership::query()->where('team_id', $fixture->team->id)->where('user_id', $fixture->member->id)->sole()->role->value)->toBe($requestedRole);
    } else {
        expect($fixture->snapshot())->toBe($before);
    }

    Notification::assertNothingSent();
})->with(['owner', 'admin', 'member', 'viewer'])->with(['owner', 'admin', 'member', 'viewer']);

it('ignores account target IDs and always projects the authenticated account', function (string $url, string $role): void {
    $fixture = TeamAuthorizationFixture::create($role);
    $this->actingAs($fixture->actor)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get($url.'?user_id='.$fixture->foreign->owner_id.'&team_id='.$fixture->foreign->id)
        ->assertOk()
        ->assertDontSee($fixture->foreign->owner->email, false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.email', $fixture->actor->email)
            ->missing('auth.user.password')
            ->missing('auth.user.current_team_id'));
})->with(['/account', '/account/security'])->with(['owner', 'admin', 'member', 'viewer']);

it('does not accept posted invitation credentials without a proved server-side continuation', function (string $identity): void {
    $fixture = TeamAuthorizationFixture::create();

    if ($identity !== 'guest') {
        $outsider = User::factory()->create(['email' => $fixture->invitation->email]);

        if ($identity === 'unverified') {
            $outsider->forceFill(['email_verified_at' => null])->save();
        }

        $this->actingAs($outsider);
    }

    Notification::fake();
    $before = $fixture->snapshot();
    $response = $this->post('/invitations/accept', [
        'invitation_id' => $fixture->invitation->id,
        'hash' => $fixture->invitation->token_hash,
        'token' => basename(parse_url($fixture->capabilityUrl, PHP_URL_PATH)),
        'team_id' => $fixture->team->id,
        'role' => 'owner',
    ]);

    if ($identity === 'verified') {
        $response->assertSessionHasErrors('invitation');
    } else {
        $response->assertRedirect($identity === 'guest' ? '/login' : '/email/verify');
    }

    expect($fixture->snapshot())->toBe($before);
    Notification::assertNothingSent();
})->with(['guest', 'unverified', 'verified']);


it('keeps account listing and account switching behind authentication', function (string $name): void {
    $fixture = TeamAuthorizationFixture::create();
    Notification::fake();
    $before = $fixture->snapshot();
    $this->call(TeamRouteMatrix::OTHER_ROUTES[$name], route($name, absolute: false))->assertRedirect('/login');
    expect($fixture->snapshot())->toBe($before);
    Notification::assertNothingSent();
})->with(['teams.index', 'account', 'account.security', 'invitations.switch-account']);

it('requires email verification for cross-team listing and account pages', function (string $name): void {
    $fixture = TeamAuthorizationFixture::create();
    $fixture->actor->forceFill(['email_verified_at' => null])->save();
    $before = $fixture->snapshot();
    $this->actingAs($fixture->actor)->get(route($name, absolute: false))->assertRedirect('/email/verify');
    expect($fixture->snapshot())->toBe($before);
})->with(['teams.index', 'account', 'account.security']);

it('lets an unverified invitee switch accounts without joining or exposing private data', function (): void {
    $fixture = TeamAuthorizationFixture::create();
    $user = User::factory()->unverified()->create(['email' => $fixture->invitation->email]);
    $this->actingAs($user)->get($fixture->capabilityUrl);
    $before = $fixture->snapshot();
    $this->post('/invitations/switch-account')->assertRedirect('/login');
    $this->assertGuest();
    $this->get('/invitations/accept')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user', null)
        ->where('invitation.email', $user->email)
        ->missing('invitation.members'));
    expect($fixture->snapshot())->toBe($before);
});
