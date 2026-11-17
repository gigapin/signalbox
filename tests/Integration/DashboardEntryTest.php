<?php

declare(strict_types=1);

/** Verify membership-safe authenticated dashboard landing and the neutral team chooser. */

use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

it('opens the sole accessible team directly after login', function (): void {
    $team = Team::factory()->create();
    $this->post('/login', ['email' => $team->owner->email, 'password' => 'password'])
        ->assertRedirect('/teams/'.$team->id.'/dashboard');
});

it('shows a dashboard chooser for multiple teams without a valid preference', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $actor = $team->owner;
    $other->users()->attach($actor->id, ['role' => 'viewer']);
    $actor->forceFill(['current_team_id' => null])->save();
    $this->actingAs($actor)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard/Index')->has('teamNavigation.teams', 2));
});

it('ignores foreign preferences and redirects only to an accessible team', function (): void {
    $team = Team::factory()->create();
    $foreign = Team::factory()->create();
    $actor = $team->owner;
    $actor->forceFill(['current_team_id' => $foreign->id])->save();
    $this->actingAs($actor)->get('/dashboard')->assertRedirect('/teams/'.$team->id.'/dashboard');
});

it('renders an honest empty dashboard for accounts without memberships', function (): void {
    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard/Index')->has('teamNavigation.teams', 0));
});

it('preserves a requested destination after login', function (): void {
    $actor = User::factory()->create();
    $this->withSession(['url.intended' => '/account/security'])->post('/login', ['email' => $actor->email, 'password' => 'password'])->assertRedirect('/account/security');
});

it('requires authentication and verification before dashboard entry', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/dashboard')->assertRedirect('/email/verify');
});
