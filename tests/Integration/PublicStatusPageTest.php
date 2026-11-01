<?php

declare(strict_types=1);

/** Exercise the anonymous status page surface: visibility, explicit projection, password and rate limit. */

use App\Actions\StatusPages\ManageStatusPages;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    Cache::flush();
    $this->team = Team::factory()->create(['name' => 'Private team name']);
    $this->monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create(['name' => 'db-primary-internal']));
});

/**
 * @param Team $team Owning team.
 * @param Monitor $monitor Monitor grouped by the page's only component.
 * @param array<string, mixed> $overrides Replaced input fields.
 * @return StatusPage Page saved through the action.
 */
function publicStatusPage(Team $team, Monitor $monitor, array $overrides = []): StatusPage
{
    $values = [
        'slug' => 'acme-status',
        'title' => 'Acme status',
        'description' => 'Current state of Acme.',
        'theme' => new StatusPageTheme(StatusPageThemeMode::Dark, StatusPageAccent::Blue),
        'isPublic' => true,
        'password' => null,
        'removePassword' => false,
        'components' => [['name' => 'Public API', 'monitor_ids' => [$monitor->id]]],
        ...$overrides,
    ];

    return app(ManageStatusPages::class)->create($team->owner, $team->id, new StatusPageInput(...$values));
}

/**
 * @param StatusPage $page Page to change.
 * @param Team $team Owning team.
 * @param array<string, mixed> $overrides Replaced input fields.
 */
function changePublicStatusPage(StatusPage $page, Team $team, array $overrides): void
{
    $values = [
        'slug' => $page->slug,
        'title' => $page->title,
        'description' => $page->description,
        'theme' => $page->theme(),
        'isPublic' => $page->is_public,
        'password' => null,
        'removePassword' => false,
        'components' => [],
        ...$overrides,
    ];

    app(ManageStatusPages::class)->update($team->owner, $team->id, $page->id, new StatusPageInput(...$values));
}

it('shows a published page to anonymous visitors with only the explicit projection', function (): void {
    publicStatusPage($this->team, $this->monitor);

    $response = $this->get('/status/acme-status')->assertOk();

    $response->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('StatusPages/Public')
        ->where('page', [
            'title' => 'Acme status',
            'description' => 'Current state of Acme.',
            'theme' => ['mode' => 'dark', 'accent' => 'blue'],
            'components' => [['name' => 'Public API']],
        ]));

    $response->assertDontSee('db-primary-internal', false)
        ->assertDontSee('Private team name', false)
        ->assertDontSee('team_id', false);
});

it('keeps components in their chosen order on the public page', function (): void {
    $other = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create());

    publicStatusPage($this->team, $this->monitor, ['components' => [
        ['name' => 'Zeta', 'monitor_ids' => [$this->monitor->id]],
        ['name' => 'Alpha', 'monitor_ids' => [$other->id]],
    ]]);

    $this->get('/status/acme-status')->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('page.components', [['name' => 'Zeta'], ['name' => 'Alpha']]));
});

it('answers drafts and unknown addresses identically, for members too', function (): void {
    publicStatusPage($this->team, $this->monitor, ['slug' => 'draft-page', 'isPublic' => false, 'password' => 'correct-password']);

    $this->get('/status/draft-page')->assertNotFound();
    $this->get('/status/unknown-page')->assertNotFound();
    $this->get('/status/Not_Valid')->assertNotFound();
    $this->post('/status/draft-page/unlock', ['password' => 'correct-password'])->assertNotFound();
    $this->post('/status/unknown-page/unlock', ['password' => 'correct-password'])->assertNotFound();

    $this->actingAs($this->team->owner)->get('/status/draft-page')->assertNotFound();
});

it('hides everything about a protected page until the password is entered', function (): void {
    publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);

    $response = $this->get('/status/acme-status')->assertOk();

    $response->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('StatusPages/Unlock')
        ->where('slug', 'acme-status')
        ->missing('page'));

    $response->assertDontSee('Acme status', false)
        ->assertDontSee('Public API', false)
        ->assertDontSee('Current state of Acme', false);
});

it('requires the password of a protected page for members of its team too', function (): void {
    publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);

    $this->actingAs($this->team->owner)->get('/status/acme-status')
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('StatusPages/Unlock'));
});

it('unlocks a protected page for the session with the right password only', function (): void {
    publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);

    $this->from('/status/acme-status')
        ->post('/status/acme-status/unlock', ['password' => 'wrong-password'])
        ->assertRedirect('/status/acme-status')
        ->assertSessionHasErrors('password');

    $this->get('/status/acme-status')
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('StatusPages/Unlock'));

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password'])
        ->assertRedirect('/status/acme-status')
        ->assertSessionHasNoErrors();

    $this->get('/status/acme-status')
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('StatusPages/Public')
            ->where('page.title', 'Acme status'));
});

it('stores a fingerprint in the session, never the password or its hash', function (): void {
    $page = publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);
    $hash = app(TenantContext::class)->run($this->team->id, fn (): ?string => StatusPage::query()->findOrFail($page->id)->password_hash);

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password']);

    $stored = session('status_page_unlocks.'.$page->id);

    expect($stored)->toBeString()
        ->not->toBe($hash)
        ->not->toBe('correct-password');
});

it('does not carry an unlock over to another page', function (): void {
    publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);
    publicStatusPage($this->team, $this->monitor, ['slug' => 'second-page', 'password' => 'correct-password', 'components' => []]);

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password']);

    $this->get('/status/second-page')
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('StatusPages/Unlock'));
});

it('locks out earlier visitors when the password changes and opens the page when it is removed', function (): void {
    $page = publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password']);
    $this->get('/status/acme-status')->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('StatusPages/Public'));

    changePublicStatusPage($page, $this->team, ['password' => 'another-password']);
    $this->get('/status/acme-status')->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('StatusPages/Unlock'));

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password'])->assertSessionHasErrors('password');

    changePublicStatusPage($page, $this->team, ['removePassword' => true]);
    $this->get('/status/acme-status')->assertInertia(fn (AssertableInertia $inertia) => $inertia->component('StatusPages/Public'));
});

it('closes an unlocked page as soon as it returns to draft', function (): void {
    $page = publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password']);
    changePublicStatusPage($page, $this->team, ['isPublic' => false]);

    $this->get('/status/acme-status')->assertNotFound();
});

it('sends a visitor of an unprotected page to it from the unlock address', function (): void {
    publicStatusPage($this->team, $this->monitor);

    $this->post('/status/acme-status/unlock', ['password' => 'anything'])
        ->assertRedirect('/status/acme-status')
        ->assertSessionHasNoErrors();
});

it('limits password attempts per page and visitor', function (): void {
    publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);
    publicStatusPage($this->team, $this->monitor, ['slug' => 'second-page', 'password' => 'correct-password', 'components' => []]);

    foreach (range(1, 5) as $attempt) {
        $this->post('/status/acme-status/unlock', ['password' => 'wrong-'.$attempt])->assertRedirect();
    }

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password'])->assertTooManyRequests();
    $this->post('/status/second-page/unlock', ['password' => 'wrong'])->assertRedirect();
});

it('tells browsers and intermediaries not to store public responses', function (): void {
    publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);

    expect($this->get('/status/acme-status')->headers->get('Cache-Control'))->toContain('no-store');

    $this->post('/status/acme-status/unlock', ['password' => 'correct-password']);

    expect($this->get('/status/acme-status')->headers->get('Cache-Control'))->toContain('no-store');
});

it('requires a bounded password field', function (mixed $password): void {
    publicStatusPage($this->team, $this->monitor, ['password' => 'correct-password']);

    $this->post('/status/acme-status/unlock', ['password' => $password])->assertSessionHasErrors('password');
})->with([
    'missing' => [null],
    'too long' => [str_repeat('x', 256)],
]);
