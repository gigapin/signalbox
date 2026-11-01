<?php

declare(strict_types=1);

/** Verify the monthly report over real ledger rows on PostgreSQL: team timezone, retained data, tenancy and delivery. */

use App\Contracts\Reports\PdfRenderer;
use App\Data\Reports\ReportMonth;
use App\Enums\Monitoring\CheckError;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Metrics\Rollups\BuildCheckRollups;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Reports\BuildMonthlyReport;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingPdfRenderer;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->travelTo(CarbonImmutable::parse('2026-03-20T12:00:00Z'));
    $this->team = Team::factory()->business()->create(['timezone' => 'Europe/Rome']);
    $this->renderer = new RecordingPdfRenderer();
    $this->app->instance(PdfRenderer::class, $this->renderer);
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Owning team.
 * @param string $name Monitor name.
 * @param string $createdAt Monitor creation instant.
 * @return Monitor Monitor with a five-minute cadence.
 */
function reportMonitor(Team $team, string $name, string $createdAt): Monitor
{
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create(['name' => $name, 'interval_seconds' => 300]));

    DB::table('monitors')->where('id', $monitor->id)->update(['created_at' => $createdAt]);

    return app(TenantContext::class)->run($team->id, fn () => $monitor->refresh());
}

/**
 * @param Monitor $monitor Owning monitor.
 * @param string $slot Slot instant, also the observation instant.
 * @param string $status Observed status.
 */
function reportSlot(Monitor $monitor, string $slot, string $status): void
{
    $slotAt = CarbonImmutable::parse($slot)->utc();

    app(ResultPartitions::class)->ensure($slotAt);

    $id = DB::table('check_executions')->insertGetId([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'region' => 'eu',
        'slot_at' => $slotAt,
        'expires_at' => $slotAt->addSeconds(300),
        'monitor_revision' => $monitor->execution_revision,
        'state' => 'completed',
        'available_at' => $slotAt,
        'finished_at' => $slotAt,
    ]);

    DB::table('check_results')->insert([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'execution_id' => $id,
        'region' => 'eu',
        'slot_at' => $slotAt,
        'monitor_revision' => $monitor->execution_revision,
        'kind' => 'observed',
        'status' => $status,
        'checked_at' => $slotAt,
        'recorded_at' => $slotAt,
        'response_time_ms' => 100,
        'error_code' => $status === 'down' ? 'synthetic_error' : null,
        'error_message' => $status === 'down' ? 'Synthetic diagnostic.' : null,
    ]);
}

/**
 * @param Monitor $monitor Owning monitor.
 * @param string $startedAt Start of the incident.
 */
function reportIncident(Monitor $monitor, string $startedAt): void
{
    DB::table('incidents')->insert([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'status' => 'resolved',
        'severity' => 'major',
        'cause' => CheckError::cases()[0]->value,
        'confirmed_at' => $startedAt,
        'started_at' => $startedAt,
        'resolved_at' => CarbonImmutable::parse($startedAt)->addMinutes(5),
        'duration_seconds' => 300,
    ]);
}

/**
 * @param Team $team Team of the report.
 * @param string $month Month as YYYY-MM.
 * @return list<App\Data\Reports\MonthlyReportRow> Report rows.
 */
function reportRows(Team $team, string $month): array
{
    return app(TenantContext::class)->run($team->id, fn () => app(BuildMonthlyReport::class)->build($team, ReportMonth::parse($month, $team->timezone)))->rows;
}

it('counts a slot and an incident by the local month, not the UTC one', function (): void {
    $monitor = reportMonitor($this->team, 'Site', '2026-01-01T00:00:00Z');

    // 00:30 on March 1st in Rome, still February in UTC.
    reportSlot($monitor, '2026-02-28T23:30:00Z', 'down');
    reportIncident($monitor, '2026-02-28T23:30:00Z');
    // 23:30 on February 28th in Rome.
    reportSlot($monitor, '2026-02-28T22:30:00Z', 'down');
    reportIncident($monitor, '2026-02-28T22:30:00Z');

    // February is beyond the 30 readable days of raw results, so it answers from rollups, as after any nightly run.
    app(ResultPartitions::class)->ensure(CarbonImmutable::parse('2026-01-15T00:00:00Z'));
    app(BuildCheckRollups::class)->runPass();

    $march = reportRows($this->team, '2026-03')[0];
    $february = reportRows($this->team, '2026-02')[0];

    expect($march->incidents)->toBe(1)
        ->and($march->down())->toBe('10m 0s')
        ->and($february->incidents)->toBe(1)
        ->and($february->down())->toBe('10m 0s');
});

it('applies the UTC month to a team in UTC', function (): void {
    $this->team->update(['timezone' => 'UTC']);
    $monitor = reportMonitor($this->team, 'Site', '2026-01-01T00:00:00Z');

    reportSlot($monitor, '2026-02-28T23:30:00Z', 'down');
    reportIncident($monitor, '2026-02-28T23:30:00Z');

    expect(reportRows($this->team, '2026-02')[0]->incidents)->toBe(1)
        ->and(reportRows($this->team, '2026-03')[0]->incidents)->toBe(0);
});

it('gives the same values from rollups once raw results are out of reach', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-02-20T00:00:00Z'));
    $monitor = reportMonitor($this->team, 'Site', '2026-01-10T00:00:00Z');

    reportSlot($monitor, '2026-01-15T12:00:00Z', 'down');

    $this->travelTo(CarbonImmutable::parse('2026-02-05T00:00:00Z'));
    app(BuildCheckRollups::class)->runPass();

    $fresh = reportRows($this->team, '2026-01')[0];

    $this->travelTo(CarbonImmutable::parse('2026-04-20T00:00:00Z'));

    $later = reportRows($this->team, '2026-01')[0];

    expect($fresh->down())->toBe('10m 0s')
        ->and($later->down())->toBe('10m 0s')
        ->and($later->sla())->toBe($fresh->sla())
        ->and($later->coverage())->toBe($fresh->coverage());
});

it('declares unavailable a month the retained data cannot reconstruct, with no partial answer', function (string $timezone): void {
    $this->team->update(['timezone' => $timezone]);
    $monitor = reportMonitor($this->team, 'Site', '2025-12-01T00:00:00Z');

    reportSlot($monitor, '2026-01-15T12:00:00Z', 'up');
    // Hour edges exist only for whole-hour offsets, so a fractional offset stays unavailable even with rollups.
    $this->travelTo(CarbonImmutable::parse('2026-04-20T00:00:00Z'));

    if ($timezone === 'Asia/Kolkata') {
        app(BuildCheckRollups::class)->runPass();
    }

    expect(reportRows($this->team, '2026-01')[0]->sla())->toBe('Unavailable');
})->with([
    'no rollups were built' => ['UTC'],
    'fractional offset after raw results expired' => ['Asia/Kolkata'],
]);

it('reports a month in progress up to now and declares it partial', function (): void {
    reportMonitor($this->team, 'Site', '2026-01-01T00:00:00Z');

    $this->actingAs($this->team->owner)
        ->get('/teams/'.$this->team->id.'/reports/2026-03')
        ->assertOk();

    expect($this->renderer->html)->toContain('still in progress')
        ->and($this->renderer->html)->toContain('2026-03-20 13:00 +01:00');
});

it('states the period with the offsets in force, across a DST change', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-05-10T12:00:00Z'));
    reportMonitor($this->team, 'Site', '2026-01-01T00:00:00Z');

    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/reports/2026-03')->assertOk();

    expect($this->renderer->html)->toContain('2026-03-01 00:00 +01:00')
        ->and($this->renderer->html)->toContain('2026-04-01 00:00 +02:00')
        ->and($this->renderer->html)->toContain('Europe/Rome')
        ->and($this->renderer->html)->not->toContain('still in progress');
});

it('refuses a month not yet begun in the team timezone, and malformed months', function (string $month): void {
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/reports/'.$month)->assertNotFound();

    expect($this->renderer->html)->toBeNull();
})->with(['2026-04', '2026-13', '2026-00', '2026-3', 'march']);

it('uses the team timezone to decide that a month has begun', function (): void {
    // April 1st at 00:30 in Rome, while still March in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-03-31T22:30:00Z'));

    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/reports/2026-04')->assertOk();
});

it('delivers a private PDF attachment named by identifiers only', function (): void {
    $this->team->update(['name' => "Evil\"\r\nX-Injected: 1"]);

    $response = $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/reports/2026-02');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="signalbox-report-'.$this->team->id.'-2026-02.pdf"')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('X-Injected');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toBe('%PDF-recorded');
});

it('contains only this team, only names and metrics, with user text escaped', function (): void {
    $foreign = Team::factory()->business()->create(['name' => 'Foreign team']);

    reportMonitor($foreign, 'Foreign monitor', '2026-01-01T00:00:00Z');

    reportMonitor($this->team, '<img src="http://attacker.test/x.png">', '2026-01-01T00:00:00Z');

    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/reports/2026-02')->assertOk();

    expect($this->renderer->html)->toContain('&lt;img src=&quot;http://attacker.test/x.png&quot;&gt;')
        ->and($this->renderer->html)->not->toContain('<img')
        ->and($this->renderer->html)->not->toContain('Foreign')
        ->and($this->renderer->html)->not->toContain('example.invalid');
});

it('orders monitors by name and shows a team without monitors honestly', function (): void {
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/reports/2026-02')->assertOk();

    expect($this->renderer->html)->toContain('This team has no monitors');

    reportMonitor($this->team, 'Zeta', '2026-01-01T00:00:00Z');
    reportMonitor($this->team, 'Alpha', '2026-01-01T00:00:00Z');

    expect(array_map(fn ($row) => $row->monitorName, reportRows($this->team, '2026-02')))->toBe(['Alpha', 'Zeta']);
});

it('refuses another team, guests and unverified accounts', function (): void {
    $foreign = Team::factory()->business()->create();
    $url = '/teams/'.$foreign->id.'/reports/2026-02';

    $this->actingAs($this->team->owner)->get($url)->assertNotFound();

    expect($this->renderer->html)->toBeNull();

    auth()->logout();
    $this->get($url)->assertRedirect();

    // A member whose address is not verified is sent to verification, and an unrelated account learns nothing.
    $this->team->owner->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/reports/2026-02')->assertRedirect();
    $this->actingAs(User::factory()->unverified()->create())->get($url)->assertNotFound();

    expect($this->renderer->html)->toBeNull();
});

it('limits downloads per user', function (): void {
    $url = '/teams/'.$this->team->id.'/reports/2026-02';

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($this->team->owner)->get($url)->assertOk();
    }

    $this->actingAs($this->team->owner)->get($url)->assertTooManyRequests();
});
