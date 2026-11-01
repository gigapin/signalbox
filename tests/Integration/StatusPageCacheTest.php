<?php

declare(strict_types=1);

/** Exercise the status page cache on PostgreSQL: isolation, invalidation, protected pages and cache failures. */

use App\Actions\Incidents\ManageIncidents;
use App\Actions\Incidents\ManageMaintenanceWindows;
use App\Actions\Monitoring\ManageMonitors;
use App\Actions\StatusPages\ManageStatusPages;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Services\StatusPages\StatusPageCache;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    // Redis measures real time, so a store following the test clock is needed; Redis has its own case below.
    $this->app->instance(StatusPageCache::class, new StatusPageCache(Cache::store('array')));
    $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    $this->team = Team::factory()->create();
    $this->monitor = cachedMonitor($this->team);
    $this->page = cachedPage($this->team, $this->monitor);
});

/**
 * @param Team $team Owning team.
 * @return Monitor Monitor observed up one minute ago, checked every five minutes.
 */
function cachedMonitor(Team $team): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create([
        'interval_seconds' => 300,
        'last_status' => MonitorStatus::Up,
        'last_checked_at' => CarbonImmutable::now('UTC')->subMinute(),
    ]));
}

/**
 * @param Monitor $monitor Monitor of the only component.
 * @param array<string, mixed> $overrides Replaced input fields.
 * @return StatusPageInput Published page input.
 */
function cachedInput(Monitor $monitor, array $overrides = []): StatusPageInput
{
    return new StatusPageInput(...[
        'slug' => 'acme-status',
        'title' => 'Acme status',
        'description' => null,
        'theme' => new StatusPageTheme(StatusPageThemeMode::System, StatusPageAccent::Emerald),
        'isPublic' => true,
        'password' => null,
        'removePassword' => false,
        'components' => [['name' => 'API', 'monitor_ids' => [$monitor->id]]],
        ...$overrides,
    ]);
}

/**
 * @param Team $team Owning team.
 * @param Monitor $monitor Monitor of the only component.
 * @param array<string, mixed> $overrides Replaced input fields.
 * @return StatusPage Saved page.
 */
function cachedPage(Team $team, Monitor $monitor, array $overrides = []): StatusPage
{
    return app(ManageStatusPages::class)->create($team->owner, $team->id, cachedInput($monitor, $overrides));
}

/**
 * @param string $slug Public address.
 * @return array<string, mixed> Projection served to a visitor now.
 */
function cachedProps(string $slug = 'acme-status'): array
{
    return test()->get('/status/'.$slug)->assertOk()->viewData('page')['props']['page'];
}

/**
 * @param Monitor $monitor Monitor whose stored state is changed behind the cache, as a check would.
 */
function failBehindCache(Monitor $monitor): void
{
    DB::table('monitors')->where('id', $monitor->id)->update(['is_paused' => true]);
}

it('serves the stored content until it expires, without reading the content again', function (): void {
    expect(cachedProps()['components'][0]['status'])->toBe('operational');

    failBehindCache($this->monitor);
    DB::enableQueryLog();

    expect(cachedProps()['components'][0]['status'])->toBe('operational');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(static fn (string $query): bool => str_contains($query, 'check_rollups_daily') || str_contains($query, 'incident')))->toBeEmpty();

    $this->travel(StatusPageCache::TTL_SECONDS - 1)->seconds();
    expect(cachedProps()['components'][0]['status'])->toBe('operational');

    $this->travel(1)->seconds();
    expect(cachedProps()['components'][0]['status'])->toBe('unknown');
});

it('keeps pages and teams apart in the store', function (): void {
    $other = Team::factory()->create();
    cachedPage($other, cachedMonitor($other), ['slug' => 'other-status', 'title' => 'Other status']);
    cachedPage($this->team, $this->monitor, ['slug' => 'second-page', 'title' => 'Second page', 'components' => []]);

    foreach (range(1, 2) as $ignored) {
        expect(cachedProps()['title'])->toBe('Acme status')
            ->and(cachedProps('other-status')['title'])->toBe('Other status')
            ->and(cachedProps('second-page')['title'])->toBe('Second page');
    }
});

it('invalidates only the changed page', function (): void {
    $second = cachedPage($this->team, cachedMonitor($this->team), ['slug' => 'second-page', 'title' => 'Second page']);
    cachedProps();
    cachedProps('second-page');
    DB::table('status_pages')->where('id', $second->id)->update(['title' => 'Written behind the cache']);
    DB::table('status_pages')->where('id', $this->page->id)->update(['title' => 'Also behind the cache']);

    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, cachedInput($this->monitor, ['title' => 'Edited']));

    expect(cachedProps()['title'])->toBe('Edited')
        ->and(cachedProps('second-page')['title'])->toBe('Second page');
});

it('applies a page edit at once at the new address and frees the old one', function (): void {
    cachedProps();

    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, cachedInput($this->monitor, ['slug' => 'renamed-page', 'title' => 'Renamed']));

    $this->get('/status/acme-status')->assertNotFound();
    expect(cachedProps('renamed-page')['title'])->toBe('Renamed');
});

it('closes a cached page at once when it returns to draft or is deleted', function (): void {
    cachedProps();

    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, cachedInput($this->monitor, ['isPublic' => false]));
    $this->get('/status/acme-status')->assertNotFound();

    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, cachedInput($this->monitor));
    cachedProps();

    app(ManageStatusPages::class)->delete($this->team->owner, $this->team->id, $this->page->id);
    $this->get('/status/acme-status')->assertNotFound();
});

it('never serves stored content to a visitor without the password', function (): void {
    cachedProps();

    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, cachedInput($this->monitor, ['password' => 'correct-password', 'title' => 'Now private']));

    $this->get('/status/acme-status')->assertOk()
        ->assertDontSee('Now private', false)
        ->assertDontSee('Acme status', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock')->missing('page'));

    // A visitor who unlocks fills the store; the next one still has to enter the password.
    $this->post('/status/acme-status/unlock', ['password' => 'correct-password']);
    expect(cachedProps()['title'])->toBe('Now private');

    $this->flushSession();
    $this->get('/status/acme-status')->assertOk()
        ->assertDontSee('Now private', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock'));
});

it('stores nothing about the visitor', function (): void {
    $this->actingAs($this->team->owner);
    $this->get('/status/acme-status')->assertOk();
    auth()->logout();

    $this->get('/status/acme-status')
        ->assertOk()
        ->assertDontSee((string) $this->team->owner->email, false)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));
});

it('still tells browsers and intermediaries not to store responses', function (): void {
    cachedProps();

    expect($this->get('/status/acme-status')->headers->get('Cache-Control'))->toContain('no-store');
});

it('shows a published incident entry at once', function (): void {
    $entry = app(TenantContext::class)->run($this->team->id, function (): IncidentUpdate {
        $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id]);

        return IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'message' => 'Now published']);
    });

    expect(cachedProps()['incidents'])->toBe([]);

    app(ManageIncidents::class)->publish($this->team->owner, $this->team->id, $entry->id);

    expect(cachedProps()['incidents'][0]['updates'][0]['message'])->toBe('Now published');
});

it('applies an incident opening, reopening or resolution at once', function (string $event): void {
    cachedProps();
    failBehindCache($this->monitor);

    event(new $event($this->team->id, 1, $this->monitor->id));

    expect(cachedProps()['components'][0]['status'])->toBe('unknown');
})->with([
    'opened' => [IncidentOpened::class],
    'reopened' => [IncidentReopened::class],
    'resolved' => [IncidentResolved::class],
]);

it('applies changes of maintenance windows at once', function (): void {
    $now = CarbonImmutable::now('UTC');
    $maintenance = app(ManageMaintenanceWindows::class);
    cachedProps();

    $window = $maintenance->schedule($this->team->owner, $this->team->id, $this->monitor->id, null, $now->addHour(), 'Window');
    expect(cachedProps()['components'][0]['status'])->toBe('maintenance');

    $this->travel(5)->seconds();
    $maintenance->cancel($this->team->owner, $this->team->id, $window->id);
    $this->travel(1)->seconds();
    expect(cachedProps()['components'][0]['status'])->toBe('operational');
});

it('applies a rescheduled window at once', function (): void {
    $now = CarbonImmutable::now('UTC');
    $maintenance = app(ManageMaintenanceWindows::class);
    $window = $maintenance->schedule($this->team->owner, $this->team->id, $this->monitor->id, $now->addHour(), $now->addHours(2), 'Later');
    expect(cachedProps()['components'][0]['status'])->toBe('operational');

    $maintenance->reschedule($this->team->owner, $this->team->id, $window->id, null, $now->addHour(), 'Now');

    expect(cachedProps()['components'][0]['status'])->toBe('maintenance');
});

it('applies a pause, a resume and a deletion of a monitor at once', function (): void {
    $monitors = app(ManageMonitors::class);
    cachedProps();

    $monitors->pause($this->team->owner, $this->team->id, $this->monitor->id);
    expect(cachedProps()['components'][0]['status'])->toBe('unknown');

    $monitors->resume($this->team->owner, $this->team->id, $this->monitor->id);
    expect(cachedProps()['components'][0]['status'])->toBe('operational');

    $monitors->delete($this->team->owner, $this->team->id, $this->monitor->id);
    expect(cachedProps()['components'][0]['status'])->toBe('unknown');
});

it('does not let a calculation running during a change fill the store with old content', function (): void {
    $cache = app(StatusPageCache::class);

    $first = $cache->remember($this->team->id, 99, function () use ($cache): array {
        // The change commits while this visitor is still calculating.
        $cache->forgetPage($this->team->id, 99);

        return ['title' => 'old'];
    });

    expect($first)->toBe(['title' => 'old'])
        ->and($cache->remember($this->team->id, 99, static fn (): array => ['title' => 'new']))->toBe(['title' => 'new']);
});

it('invalidates only after the change commits', function (): void {
    $cache = app(StatusPageCache::class);
    $cache->remember($this->team->id, 99, static fn (): array => ['title' => 'old']);

    DB::transaction(function () use ($cache): void {
        $cache->forgetPage($this->team->id, 99);

        // Inside the transaction a reader would recalculate the old content, so the entry must stay valid.
        expect($cache->remember($this->team->id, 99, static fn (): array => ['title' => 'recalculated']))->toBe(['title' => 'old']);
    });

    expect($cache->remember($this->team->id, 99, static fn (): array => ['title' => 'new']))->toBe(['title' => 'new']);
});

it('does not invalidate when the change rolls back', function (): void {
    $cache = app(StatusPageCache::class);
    $cache->remember($this->team->id, 99, static fn (): array => ['title' => 'old']);

    try {
        DB::transaction(function () use ($cache): void {
            $cache->forgetPage($this->team->id, 99);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($cache->remember($this->team->id, 99, static fn (): array => ['title' => 'new']))->toBe(['title' => 'old']);
});

it('keeps pages and changes working when the cache fails', function (): void {
    $failing = Mockery::mock(Repository::class);
    $failing->shouldReceive('add', 'get', 'put', 'increment')->andThrow(new RuntimeException('secret-connection-detail'));
    $this->app->instance(StatusPageCache::class, new StatusPageCache($failing));
    Log::spy();

    expect(cachedProps()['title'])->toBe('Acme status');

    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, cachedInput($this->monitor, ['title' => 'Edited']));

    expect(cachedProps()['title'])->toBe('Edited');

    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secret-connection-detail'));
});

it('works on the Redis store used outside tests', function (): void {
    $cache = new StatusPageCache(Cache::store('redis'));
    $pageId = random_int(1_000_000, 999_999_999);

    try {
        expect($cache->remember($this->team->id, $pageId, static fn (): array => ['title' => 'first', 'uptime' => 99.5, 'none' => null]))
            ->toBe(['title' => 'first', 'uptime' => 99.5, 'none' => null])
            ->and($cache->remember($this->team->id, $pageId, static fn (): array => ['title' => 'unused']))
            ->toBe(['title' => 'first', 'uptime' => 99.5, 'none' => null]);

        $cache->forgetPage($this->team->id, $pageId);

        expect($cache->remember($this->team->id, $pageId, static fn (): array => ['title' => 'second']))->toBe(['title' => 'second']);
    } finally {
        foreach (['generation', 'content:1', 'content:2'] as $suffix) {
            Cache::store('redis')->forget("status-pages:{$this->team->id}:{$pageId}:{$suffix}");
        }
    }
});
