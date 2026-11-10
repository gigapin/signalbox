<?php

declare(strict_types=1);

/** Verify private membership projections and confirmed selection against isolated PostgreSQL. */

use App\Models\Team;
use App\Models\User;
use App\Services\Identity\TeamNavigation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
});

it('projects only current membership identities and per-team roles with a valid preference', function (): void {
    $first = Team::factory()->create(['name' => 'Alpha']);
    $second = Team::factory()->create(['name' => 'Beta']);
    $foreign = Team::factory()->create();
    $actor = $first->owner;
    $second->users()->attach($actor->id, ['role' => 'viewer']);
    $actor->forceFill(['current_team_id' => $second->id])->save();

    $this->actingAs($actor)->get("/teams/{$first->id}/dashboard")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('team.id', $first->id)
            ->where('teamNavigation', [
                'teams' => [
                    ['id' => $first->id, 'name' => 'Alpha', 'role' => 'owner'],
                    ['id' => $second->id, 'name' => 'Beta', 'role' => 'viewer'],
                ],
                'selectedTeamId' => $second->id,
            ]));
    expect(array_column(app(TeamNavigation::class)->forUser($actor)['teams'], 'id'))->not->toContain($foreign->id);
    $this->get("/teams/{$first->id}/status-pages")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('does not select a fallback when a saved preference has been revoked', function (): void {
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $actor = User::factory()->create(['current_team_id' => $first->id]);
    $second->users()->attach($actor->id, ['role' => 'member']);

    $this->actingAs($actor)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('currentTeam', null)
        ->where('teamNavigation.selectedTeamId', null)
        ->has('teamNavigation.teams', 1)
        ->where('teamNavigation.teams.0.id', $second->id));
    $this->get("/teams/{$first->id}/dashboard")->assertNotFound();
    expect($actor->fresh()->current_team_id)->toBe($first->id);
});

it('handles accounts without memberships and exposes a verified dashboard identity only when accessible', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('currentTeam', null)
        ->where('teamNavigation', ['teams' => [], 'selectedTeamId' => null]));
    $team = Team::factory()->create();
    $team->users()->attach($actor->id, ['role' => 'viewer']);
    $actor->forceFill(['current_team_id' => $team->id])->save();
    $this->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('currentTeam.id', $team->id)
        ->where('currentTeam.name', $team->name));
});

it('keeps membership choices out of public authentication and operator contexts', function (): void {
    $team = Team::factory()->create();
    $actor = $team->owner;
    $actor->forceFill(['is_platform_admin' => true])->save();
    foreach (['/', '/user/confirm-password', '/admin/teams'] as $url) {
        $this->actingAs($actor)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teamNavigation', null));
    }
    $this->get('/teams')->assertInertia(fn (AssertableInertia $page) => $page->has('teamNavigation.teams', 1));
});

it('acknowledges only the authorized dashboard destination after persisting the preference', function (): void {
    $first = Team::factory()->create(['name' => 'Alpha']);
    $second = Team::factory()->create(['name' => 'Beta']);
    $actor = $first->owner;
    $second->users()->attach($actor->id, ['role' => 'viewer']);
    $this->actingAs($actor)->postJson("/teams/{$second->id}/select", [
        'role' => 'owner',
        'destination' => 'https://untrusted.example.test',
        'team_id' => $first->id,
    ])->assertOk()->assertExactJson(['destination' => "/teams/{$second->id}/dashboard"])
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($actor->fresh()->current_team_id)->toBe($second->id);
    $this->get("/teams/{$second->id}/dashboard")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('teamNavigation.teams.1.id', $second->id)
        ->where('teamNavigation.teams.1.role', 'viewer'));
});

it('redirects HTML selection explicitly without carrying source resource or forged redirects', function (): void {
    $team = Team::factory()->create();
    $this->actingAs($team->owner)->from('/account/security')->post("/teams/{$team->id}/select", [
        'returnTo' => '/admin/teams',
    ])->assertRedirect("/teams/{$team->id}/dashboard");
});

it('rejects revoked or foreign JSON selections without changing the saved team', function (): void {
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $actor = $first->owner;
    $actor->forceFill(['current_team_id' => $first->id])->save();
    $this->actingAs($actor)->postJson("/teams/{$second->id}/select")->assertNotFound();
    expect($actor->fresh()->current_team_id)->toBe($first->id);
    $second->users()->attach($actor->id, ['role' => 'viewer']);
    $second->users()->detach($actor->id);
    $this->postJson("/teams/{$second->id}/select")->assertNotFound();
    expect($actor->fresh()->current_team_id)->toBe($first->id);
});

it('reads multiple membership choices with two queries rather than one role query per team', function (): void {
    $actor = User::factory()->create();
    foreach (Team::factory()->count(6)->create() as $team) {
        $team->users()->attach($actor->id, ['role' => 'viewer']);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $projection = app(TeamNavigation::class)->forUser($actor);
        expect($projection['teams'])->toHaveCount(6)
            ->and(DB::getQueryLog())->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});
