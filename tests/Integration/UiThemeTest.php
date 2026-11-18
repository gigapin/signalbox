<?php

/** Verify account isolation, anonymous first paint and persistent appearance without tenant data. */

use App\Models\User;
use App\Services\Appearance\UiTheme;
use Illuminate\Support\Facades\Route;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL.');
    }
    $this->withoutVite();
});

it('defaults anonymous pages to dark and remembers an anonymous browser choice', function (): void {
    $this->get('/')->assertOk()->assertSee('data-ui-theme="dark"', false);
    $this->withCookie(UiTheme::COOKIE, 'light')->get('/')->assertSee('data-ui-theme="light"', false);
    $this->withCookie(UiTheme::COOKIE, 'invalid')->get('/login')->assertSee('data-ui-theme="dark"', false);
});

it('requires an authenticated verified session and declares its CSRF web boundary', function (): void {
    $this->postJson('/preferences/theme', ['theme' => 'light'])->assertUnauthorized();
    $route = Route::getRoutes()->getByName('preferences.theme');
    expect($route->gatherMiddleware())->toContain('web', 'auth', 'verified', 'throttle:60,1');
});

it('saves only the session account and remembers its choice after logout', function (): void {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($actor)->postJson('/preferences/theme', ['theme' => 'light', 'user_id' => $other->id])
        ->assertOk()->assertExactJson(['theme' => 'light'])->assertCookie(UiTheme::COOKIE);
    expect($actor->fresh()->theme_preference)->toBe('light')->and($other->fresh()->theme_preference)->toBeNull();
    $this->get('/')->assertSee('data-ui-theme="light"', false);
    $this->withCookie(UiTheme::COOKIE, 'light')->post('/logout')->assertRedirect('/');
    $this->get('/')->assertSee('data-ui-theme="light"', false);
    $this->post('/login', ['email' => $actor->email, 'password' => 'password'])->assertRedirect();
    $this->get('/')->assertSee('data-ui-theme="light"', false);
    $this->postJson('/preferences/theme', ['theme' => 'system'])->assertUnprocessable();
    $this->postJson('/preferences/theme', ['theme' => 'dark'])->assertOk();
    expect($actor->fresh()->theme_preference)->toBe('dark');
});

it('uses account dark by default even when the browser was previously light', function (): void {
    $this->actingAs(User::factory()->create())->withCookie(UiTheme::COOKIE, 'light')
        ->get('/')->assertSee('data-ui-theme="dark"', false);
});
