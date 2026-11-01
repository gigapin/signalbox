<?php

declare(strict_types=1);

/** Exercise what a status page publishes on PostgreSQL: current state, 90-day history and explicitly public incident entries. */

use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\MonitorStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusPageCache;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    // Redis measures real time, and entries from an earlier run could share these identities.
    $this->app->instance(StatusPageCache::class, new StatusPageCache(Cache::store('array')));
    $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    $this->team = Team::factory()->create(['name' => 'Private team name']);
});

/**
 * @param Team $team Owning team.
 * @param array<string, mixed> $attributes Replaced attributes.
 * @return Monitor Monitor observed up one minute ago, every minute, unless overridden.
 */
function contentMonitor(Team $team, array $attributes = []): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create([
        'name' => 'internal-monitor-name',
        'interval_seconds' => 60,
        'last_status' => MonitorStatus::Up,
        'last_checked_at' => CarbonImmutable::now('UTC')->subMinute(),
        ...$attributes,
    ]));
}

/**
 * @param Team $team Owning team.
 * @param array<string, list<Monitor>> $components Monitors per component name, in display order.
 * @return StatusPage Published page at `acme-status`.
 */
function contentPage(Team $team, array $components, string $slug = 'acme-status'): StatusPage
{
    return app(TenantContext::class)->run($team->id, function () use ($team, $components, $slug): StatusPage {
        $page = StatusPage::factory()->published()->create(['slug' => $slug, 'title' => 'Acme status']);
        $position = 0;

        foreach ($components as $name => $monitors) {
            $component = new StatusPageComponent();
            $component->forceFill(['status_page_id' => $page->id, 'name' => $name, 'position' => $position++])->save();
            $component->monitors()->attach(
                array_map(static fn (Monitor $monitor): int => $monitor->id, $monitors),
                ['status_page_id' => $page->id, 'team_id' => $team->id],
            );
        }

        return $page;
    });
}

/**
 * @param Monitor $monitor Monitor of the rollup.
 * @param string $day UTC day.
 * @param int $up Up time in microseconds.
 * @param int $down Down time in microseconds.
 * @param int $unknown Unknown time in microseconds.
 * @param int $excluded Excluded time in microseconds.
 */
function contentRollup(Monitor $monitor, string $day, int $up, int $down = 0, int $unknown = 0, int $excluded = 0): void
{
    DB::table('check_rollups_daily')->insert([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'day' => $day,
        'hours' => 24,
        'checks' => 0,
        'failures' => 0,
        'unknown_checks' => 0,
        'up_us' => $up,
        'down_us' => $down,
        'unknown_us' => $unknown,
        'excluded_us' => $excluded,
        'latency_samples' => 0,
        'latency_sum_ms' => 0,
        'latency_counts' => '{}',
    ]);
}

/**
 * @param Team $team Owning team.
 * @param Monitor $monitor Failing monitor.
 * @param array<string, mixed> $attributes Changes applied after creation, as new incidents open as investigating.
 * @param bool $resolved Whether the incident is resolved instead of open.
 * @return Incident Created incident.
 */
function contentIncident(Team $team, Monitor $monitor, array $attributes = [], bool $resolved = false): Incident
{
    return app(TenantContext::class)->run($team->id, function () use ($monitor, $attributes, $resolved): Incident {
        $factory = $resolved ? Incident::factory()->resolved() : Incident::factory();
        $incident = $factory->create(['monitor_id' => $monitor->id]);
        $incident->forceFill($attributes)->save();

        return $incident;
    });
}

/**
 * @param Team $team Owning team.
 * @param Incident $incident Owning incident.
 * @param string $message Entry text.
 * @param CarbonImmutable|null $publishedAt Publication instant; null keeps the entry private.
 * @param IncidentStatus $status Status stated by the entry.
 * @param User|null $author Writing user; null for a system entry.
 * @return IncidentUpdate Created entry.
 */
function contentEntry(Team $team, Incident $incident, string $message, ?CarbonImmutable $publishedAt, IncidentStatus $status = IncidentStatus::Investigating, ?User $author = null): IncidentUpdate
{
    return app(TenantContext::class)->run($team->id, function () use ($incident, $message, $publishedAt, $status, $author): IncidentUpdate {
        $entry = IncidentUpdate::factory()->make(['incident_id' => $incident->id, 'status' => $status, 'message' => $message, 'author_id' => $author?->id]);
        $entry->forceFill(['is_public' => $publishedAt !== null, 'published_at' => $publishedAt])->save();

        return $entry;
    });
}

/**
 * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response Public page response.
 * @return array<string, mixed> Projection sent to the page.
 */
function contentProps(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props']['page'];
}

it('reports the current state of components and of the page', function (): void {
    $up = contentMonitor($this->team);
    $failing = contentMonitor($this->team);
    $alsoUp = contentMonitor($this->team);
    $resting = contentMonitor($this->team);
    $paused = contentMonitor($this->team, ['is_paused' => true]);
    $stale = contentMonitor($this->team, ['last_checked_at' => CarbonImmutable::now('UTC')->subHour()]);

    contentIncident($this->team, $failing);
    contentIncident($this->team, $up, resolved: true);
    app(TenantContext::class)->run($this->team->id, fn () => MaintenanceWindow::query()->getModel()->forceFill([
        'monitor_id' => $resting->id,
        'starts_at' => CarbonImmutable::now('UTC')->subMinute(),
        'ends_at' => CarbonImmutable::now('UTC')->addHour(),
        'reason' => 'private maintenance reason',
    ])->save());

    contentPage($this->team, [
        'Up' => [$up],
        'Half' => [$failing, $alsoUp],
        'Resting' => [$resting],
        'Paused only' => [$paused],
        'Stale' => [$stale],
    ]);

    $response = $this->get('/status/acme-status');
    $page = contentProps($response);

    expect(array_column($page['components'], 'status', 'name'))->toBe([
        'Up' => 'operational',
        'Half' => 'partial_outage',
        'Resting' => 'maintenance',
        'Paused only' => 'unknown',
        'Stale' => 'unknown',
    ])->and($page['status'])->toBe('partial_outage');

    $response->assertDontSee('private maintenance reason', false)
        ->assertDontSee('internal-monitor-name', false)
        ->assertDontSee('Private team name', false);
});

it('says all is operational only when everything is', function (): void {
    contentPage($this->team, ['API' => [contentMonitor($this->team)]]);

    expect(contentProps($this->get('/status/acme-status'))['status'])->toBe('operational');
});

it('does not claim anything for a page without components or monitors', function (): void {
    $monitor = contentMonitor($this->team);
    contentPage($this->team, []);
    contentPage($this->team, ['Emptied' => [$monitor]], 'emptied-page');
    app(TenantContext::class)->run($this->team->id, fn () => $monitor->delete());

    expect(contentProps($this->get('/status/acme-status'))['status'])->toBe('unknown')
        ->and(contentProps($this->get('/status/emptied-page'))['components'][0]['status'])->toBe('unknown');
});

it('ends maintenance exactly at its exclusive end', function (): void {
    $monitor = contentMonitor($this->team);
    $end = CarbonImmutable::now('UTC')->addHour();

    app(TenantContext::class)->run($this->team->id, fn () => MaintenanceWindow::query()->getModel()->forceFill([
        'monitor_id' => $monitor->id,
        'starts_at' => CarbonImmutable::now('UTC')->subMinute(),
        'ends_at' => $end,
        'reason' => 'Window',
    ])->save());

    contentPage($this->team, ['API' => [$monitor]]);

    expect(contentProps($this->get('/status/acme-status'))['components'][0]['status'])->toBe('maintenance');

    $this->travelTo($end);
    app(TenantContext::class)->run($this->team->id, fn () => $monitor->forceFill(['last_checked_at' => $end->subMinute()])->save());

    expect(contentProps($this->get('/status/acme-status'))['components'][0]['status'])->toBe('operational');
});

it('publishes exactly the 90 whole UTC days before today, from rollups', function (): void {
    $monitor = contentMonitor($this->team);
    contentPage($this->team, ['API' => [$monitor]]);

    contentRollup($monitor, '2026-08-16', 86_400_000_000);
    contentRollup($monitor, '2026-08-17', 86_400_000_000);
    contentRollup($monitor, '2026-11-14', 43_200_000_000, 43_200_000_000);
    contentRollup($monitor, '2026-11-15', 1_000_000);

    $component = contentProps($this->get('/status/acme-status'))['components'][0];
    $days = $component['days'];

    expect($days)->toHaveCount(90)
        ->and($days[0])->toBe(['date' => '2026-08-17', 'state' => 'up', 'uptimePercent' => 100.0, 'coveragePercent' => 100.0])
        ->and($days[1])->toBe(['date' => '2026-08-18', 'state' => 'none', 'uptimePercent' => null, 'coveragePercent' => null])
        ->and($days[89])->toBe(['date' => '2026-11-14', 'state' => 'down', 'uptimePercent' => 50.0, 'coveragePercent' => 100.0])
        ->and($component['uptimePercent'])->toBe(75.0);
});

it('keeps the 90-day limit on plans with a longer private history', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = contentMonitor($team);
    contentPage($team, ['API' => [$monitor]]);
    contentRollup($monitor, '2026-06-01', 86_400_000_000);

    $component = contentProps($this->get('/status/acme-status'))['components'][0];

    expect($component['days'])->toHaveCount(90)
        ->and($component['days'][0]['date'])->toBe('2026-08-17')
        ->and($component['uptimePercent'])->toBeNull();
});

it('never turns missing data into availability', function (): void {
    $monitor = contentMonitor($this->team);
    contentPage($this->team, ['API' => [$monitor]]);

    contentRollup($monitor, '2026-11-12', 0, 0, 86_400_000_000);
    contentRollup($monitor, '2026-11-13', 0, 0, 0, 86_400_000_000);
    contentRollup($monitor, '2026-11-14', 43_200_000_000, 0, 43_200_000_000);

    $component = contentProps($this->get('/status/acme-status'))['components'][0];

    expect(array_slice($component['days'], -3))->toBe([
        ['date' => '2026-11-12', 'state' => 'none', 'uptimePercent' => null, 'coveragePercent' => 0.0],
        ['date' => '2026-11-13', 'state' => 'none', 'uptimePercent' => null, 'coveragePercent' => null],
        ['date' => '2026-11-14', 'state' => 'up', 'uptimePercent' => 100.0, 'coveragePercent' => 50.0],
    ])->and($component['uptimePercent'])->toBe(100.0);
});

it('adds up the monitors of a component and keeps components and pages apart', function (): void {
    $first = contentMonitor($this->team);
    $second = contentMonitor($this->team);
    $other = contentMonitor($this->team);
    $outside = contentMonitor($this->team);
    contentPage($this->team, ['Both' => [$first, $second], 'Other' => [$other]]);

    contentRollup($first, '2026-11-14', 75_000_000);
    contentRollup($second, '2026-11-14', 0, 25_000_000);
    contentRollup($other, '2026-11-14', 10_000_000);
    contentRollup($outside, '2026-11-14', 0, 99_000_000);

    $page = contentProps($this->get('/status/acme-status'));

    expect($page['components'][0]['days'][89]['uptimePercent'])->toBe(75.0)
        ->and($page['components'][0]['days'][89]['state'])->toBe('down')
        ->and($page['components'][1]['days'][89]['uptimePercent'])->toBe(100.0)
        ->and($page['components'][1]['days'][89]['state'])->toBe('up');
});

it('publishes only explicitly public entries, without private details', function (): void {
    $monitor = contentMonitor($this->team);
    contentPage($this->team, ['Public API' => [$monitor]]);
    $author = User::factory()->create(['name' => 'Private author name']);

    $silent = contentIncident($this->team, $monitor, resolved: true);
    $incident = contentIncident($this->team, $monitor, ['root_cause_note' => 'private root cause', 'status' => IncidentStatus::Identified]);

    contentEntry($this->team, $incident, 'Private note', null, IncidentStatus::Identified);
    contentEntry($this->team, $silent, 'Never published', null);
    contentEntry($this->team, $incident, 'We are looking into it.', CarbonImmutable::parse('2026-11-15T10:00:00Z'), author: $author);

    $response = $this->get('/status/acme-status');

    // The latest published entry states the status, so the private move to identified reveals nothing.
    expect(contentProps($response)['incidents'])->toBe([[
        'component' => 'Public API',
        'status' => 'investigating',
        'updates' => [[
            'status' => 'investigating',
            'message' => 'We are looking into it.',
            'publishedAt' => '2026-11-15T10:00:00Z',
        ]],
    ]]);

    $response->assertDontSee('Private note', false)
        ->assertDontSee('Never published', false)
        ->assertDontSee('private root cause', false)
        ->assertDontSee('Private author name', false);
});

it('orders incidents and entries from the newest publication, within 90 days', function (): void {
    $monitor = contentMonitor($this->team);
    contentPage($this->team, ['API' => [$monitor]]);
    $older = contentIncident($this->team, $monitor, resolved: true);
    $expired = contentIncident($this->team, $monitor, resolved: true);
    $newer = contentIncident($this->team, $monitor);

    contentEntry($this->team, $older, 'Older first', CarbonImmutable::parse('2026-11-01T08:00:00Z'));
    contentEntry($this->team, $older, 'Older fixed', CarbonImmutable::parse('2026-11-01T09:00:00Z'), IncidentStatus::Resolved);
    contentEntry($this->team, $newer, 'Newer', CarbonImmutable::parse('2026-11-14T08:00:00Z'));
    contentEntry($this->team, $expired, 'Too old', CarbonImmutable::parse('2026-08-17T11:59:59Z'));

    $incidents = contentProps($this->get('/status/acme-status'))['incidents'];

    expect(array_column($incidents, 'status'))->toBe(['investigating', 'resolved'])
        ->and(array_column($incidents[1]['updates'], 'message'))->toBe(['Older fixed', 'Older first']);
});

it('does not list incidents of monitors outside the page or of other teams', function (): void {
    $onPage = contentMonitor($this->team);
    $offPage = contentMonitor($this->team);
    $otherTeam = Team::factory()->create();
    $foreign = contentMonitor($otherTeam);
    contentPage($this->team, ['API' => [$onPage]]);

    contentEntry($this->team, contentIncident($this->team, $offPage), 'Off the page', CarbonImmutable::parse('2026-11-14T08:00:00Z'));
    contentEntry($otherTeam, contentIncident($otherTeam, $foreign), 'Other team', CarbonImmutable::parse('2026-11-14T08:00:00Z'));

    $response = $this->get('/status/acme-status');
    $page = contentProps($response);

    expect($page['incidents'])->toBe([])
        ->and($page['components'][0]['status'])->toBe('operational');
    $response->assertDontSee('Off the page', false)->assertDontSee('Other team', false);
});

it('sends nothing of this content before the password is entered', function (): void {
    $monitor = contentMonitor($this->team);
    $page = contentPage($this->team, ['Public API' => [$monitor]]);
    app(TenantContext::class)->run($this->team->id, fn () => $page->forceFill(['password_hash' => bcrypt('correct-password')])->save());
    contentEntry($this->team, contentIncident($this->team, $monitor), 'Published text', CarbonImmutable::parse('2026-11-14T08:00:00Z'));

    $this->get('/status/acme-status')->assertOk()
        ->assertDontSee('Published text', false)
        ->assertDontSee('Public API', false);
});
