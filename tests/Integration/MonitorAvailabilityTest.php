<?php

declare(strict_types=1);

/** Verify time-weighted availability over raw results and rollups: exclusions, partial windows, missing data and tenancy on PostgreSQL. */

use App\Data\Metrics\AvailabilityReport;
use App\Data\Metrics\TimeInterval;
use App\Exceptions\MissingTenantContext;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Metrics\MonitorAvailability;
use App\Services\Metrics\Rollups\BuildCheckRollups;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $this->team = Team::factory()->business()->create();
    $this->monitor = slaMonitor($this->team, '2026-10-02T09:00:00Z');
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Owning team.
 * @param string $createdAt Monitor creation instant.
 * @return Monitor Monitor with a five-minute cadence and an explicit lifetime start.
 */
function slaMonitor(Team $team, string $createdAt): Monitor
{
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create(['interval_seconds' => 300]));

    DB::table('monitors')->where('id', $monitor->id)->update(['created_at' => $createdAt]);

    return app(TenantContext::class)->run($team->id, fn () => $monitor->refresh());
}

/**
 * Inserts one slot through the real composite foreign keys.
 *
 * @param Monitor $monitor Owning monitor.
 * @param string $slot Slot instant; the observation happens exactly there.
 * @param array<string, string|null> $regions Observed status per region; null is work running until the end of the slot's period.
 * @param int $interval Reserved cadence in seconds.
 */
function slaSlot(Monitor $monitor, string $slot, array $regions = ['eu' => 'up'], int $interval = 300): void
{
    $slotAt = CarbonImmutable::parse($slot)->utc();

    app(ResultPartitions::class)->ensure($slotAt);

    foreach ($regions as $region => $status) {
        $executionId = DB::table('check_executions')->insertGetId([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'region' => $region,
            'slot_at' => $slotAt,
            'expires_at' => $slotAt->addSeconds($interval),
            'monitor_revision' => $monitor->execution_revision,
            'state' => $status === null ? 'running' : 'completed',
            'available_at' => $slotAt,
            'deadline_at' => $status === null ? $slotAt->addSeconds($interval) : null,
            'acquisition_token' => $status === null ? (string) Str::uuid() : null,
            'started_at' => $status === null ? $slotAt : null,
            'finished_at' => $status === null ? null : $slotAt,
        ]);

        if ($status === null) {
            continue;
        }

        DB::table('check_results')->insert([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'execution_id' => $executionId,
            'region' => $region,
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
}

/**
 * @param Monitor $monitor Monitor to fill.
 * @param string $from First slot instant.
 * @param string $to Exclusive end of the slots.
 * @param array<string, string|null> $regions Observed status per region.
 */
function slaSlots(Monitor $monitor, string $from, string $to, array $regions = ['eu' => 'up']): void
{
    $end = CarbonImmutable::parse($to);

    for ($slot = CarbonImmutable::parse($from); $slot->isBefore($end); $slot = $slot->addSeconds(300)) {
        slaSlot($monitor, $slot->toIso8601String(), $regions);
    }
}

/**
 * @param Monitor $monitor Monitor to read.
 * @param string $from Window start.
 * @param string $to Window end.
 * @return AvailabilityReport Report calculated inside the monitor's tenant.
 */
function slaBetween(Monitor $monitor, string $from, string $to): AvailabilityReport
{
    return app(TenantContext::class)->run($monitor->team_id, fn () => app(MonitorAvailability::class)->between(
        $monitor,
        new TimeInterval(CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
    ));
}

/**
 * @param AvailabilityReport $report Calculated report.
 * @return list<int> Up, down, unknown and excluded time in whole seconds.
 */
function slaSeconds(AvailabilityReport $report): array
{
    $durations = $report->durations;

    expect($durations)->not->toBeNull();

    return [
        intdiv($durations->up, 1_000_000),
        intdiv($durations->down, 1_000_000),
        intdiv($durations->unknown, 1_000_000),
        intdiv($durations->excluded, 1_000_000),
    ];
}

/**
 * @param Monitor $monitor Monitor to change.
 * @param string $hour UTC hour of the stored rollup.
 * @param int $upSeconds Distinctive up time proving this row was the source of the answer.
 */
function slaMarkRollup(Monitor $monitor, string $hour, int $upSeconds): void
{
    DB::table('check_rollups_hourly')
        ->where('monitor_id', $monitor->id)
        ->where('hour', CarbonImmutable::parse($hour)->format('Y-m-d\TH:i:s\Z'))
        ->update(['up_us' => $upSeconds * 1_000_000, 'down_us' => 0, 'unknown_us' => 0, 'excluded_us' => 0]);
}

/**
 * @param Monitor $monitor Monitor to rebuild from.
 * @param string $from Window start.
 * @param string $to Window end.
 * @return list<int> The same window's durations once only raw results can answer.
 */
function slaRawSeconds(Monitor $monitor, string $from, string $to): array
{
    $stored = DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->get()->map(static fn ($row) => (array) $row)->all();

    DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->delete();
    $seconds = slaSeconds(slaBetween($monitor, $from, $to));

    DB::table('check_rollups_hourly')->insert(array_map(static fn (array $row) => array_diff_key($row, ['uptime_ratio' => true, 'avg_ms' => true]), $stored));

    return $seconds;
}

it('weights uptime by time rather than by the number of checks', function (): void {
    for ($minute = 0; $minute < 30; ++$minute) {
        slaSlot($this->monitor, CarbonImmutable::parse('2026-10-03T09:00:00Z')->addMinutes($minute)->toIso8601String(), interval: 60);
    }

    slaSlots($this->monitor, '2026-10-03T09:30:00Z', '2026-10-03T10:00:00Z', ['eu' => 'down']);

    $report = slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T10:00:00Z');

    // Thirty of thirty-six checks succeeded, which a count would call 83%, but the outage lasted half of the hour.
    expect(slaSeconds($report))->toBe([1800, 1800, 0, 0])
        ->and($report->slaPercent())->toBe(50.0)
        ->and($report->coveragePercent())->toBe(100.0);
});

it('keeps SLA null with a gap but still reports observed uptime and coverage', function (): void {
    slaSlots($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T09:30:00Z');
    slaSlots($this->monitor, '2026-10-03T09:50:00Z', '2026-10-03T10:00:00Z');

    $report = slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T10:00:00Z');

    // The last sample at 09:25 holds until 09:35; 09:35-09:50 has no valid verdict.
    expect(slaSeconds($report))->toBe([2700, 0, 900, 0])
        ->and($report->observedPercent())->toBe(100.0)
        ->and($report->coveragePercent())->toBe(75.0)
        ->and($report->slaPercent())->toBeNull();
});

it('reports a window without any data as unknown, never as 100 percent', function (): void {
    $report = slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T10:00:00Z');

    expect(slaSeconds($report))->toBe([0, 0, 3600, 0])
        ->and($report->observedPercent())->toBeNull()
        ->and($report->coveragePercent())->toBe(0.0)
        ->and($report->slaPercent())->toBeNull();
});

it('subtracts overlapping pauses and maintenance once, making excluded gaps irrelevant to the SLA', function (): void {
    slaSlots($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T09:15:00Z');

    DB::table('monitor_pause_periods')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'kind' => 'voluntary',
        'started_at' => '2026-10-03T09:25:00Z',
        'ended_at' => '2026-10-03T09:50:00Z',
    ]);
    DB::table('maintenance_windows')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'starts_at' => '2026-10-03T09:40:00Z',
        'ends_at' => '2026-10-03T10:00:00Z',
        'reason' => 'Synthetic maintenance.',
    ]);

    $report = slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T10:00:00Z');

    // Up until 09:20 (last sample plus two periods); 09:20-09:25 is unknown; 09:25-10:00 is excluded once.
    expect(slaSeconds($report))->toBe([1200, 0, 300, 2100])
        ->and($report->slaPercent())->toBeNull();

    DB::table('maintenance_windows')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'starts_at' => '2026-10-03T09:20:00Z',
        'ends_at' => '2026-10-03T09:25:00Z',
        'reason' => 'Synthetic maintenance.',
    ]);

    $covered = slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T10:00:00Z');

    expect(slaSeconds($covered))->toBe([1200, 0, 0, 2400])
        ->and($covered->slaPercent())->toBe(100.0);
});

it('leaves every ratio null when the whole window is excluded', function (): void {
    DB::table('monitor_pause_periods')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'kind' => 'plan_suspension',
        'started_at' => '2026-10-03T08:00:00Z',
        'ended_at' => null,
    ]);

    $report = slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T10:00:00Z');

    expect(slaSeconds($report))->toBe([0, 0, 0, 3600])
        ->and($report->coveragePercent())->toBeNull()
        ->and($report->observedPercent())->toBeNull()
        ->and($report->slaPercent())->toBeNull();
});

it('clips the window to the life of the monitor and to the current instant', function (string $from, string $to, array $expected): void {
    expect(slaSeconds(slaBetween($this->monitor, $from, $to)))->toBe($expected);
})->with([
    'starting before creation' => ['2026-10-02T08:00:00Z', '2026-10-02T10:00:00Z', [0, 0, 3600, 0]],
    'entirely before creation' => ['2026-10-01T00:00:00Z', '2026-10-02T00:00:00Z', [0, 0, 0, 0]],
    'ending in the future' => ['2026-10-03T11:00:00Z', '2026-10-03T14:00:00Z', [0, 0, 3600, 0]],
    'entirely in the future' => ['2026-10-04T00:00:00Z', '2026-10-05T00:00:00Z', [0, 0, 0, 0]],
]);

it('gives the same durations from raw results and from rollups, at any window edge', function (string $from, string $to): void {
    slaSlots($this->monitor, '2026-10-02T09:00:00Z', '2026-10-02T14:00:00Z');
    slaSlot($this->monitor, '2026-10-02T14:00:00Z', ['eu' => 'down']);
    slaSlot($this->monitor, '2026-10-02T14:05:00Z', ['eu' => 'down']);
    slaSlots($this->monitor, '2026-10-02T14:10:00Z', '2026-10-02T18:00:00Z');
    DB::table('maintenance_windows')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'starts_at' => '2026-10-02T11:30:00Z',
        'ends_at' => '2026-10-02T12:30:00Z',
        'reason' => 'Synthetic maintenance.',
    ]);

    app(BuildCheckRollups::class)->advance($this->team->id, $this->monitor->id);

    expect(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->count())->toBeGreaterThan(0)
        ->and(slaSeconds(slaBetween($this->monitor, $from, $to)))->toBe(slaRawSeconds($this->monitor, $from, $to));
})->with([
    'whole hours' => ['2026-10-02T10:00:00Z', '2026-10-02T16:00:00Z'],
    'edges inside hours' => ['2026-10-02T10:20:00Z', '2026-10-02T15:40:00Z'],
    'half-hour timezone edges' => ['2026-10-02T09:30:00Z', '2026-10-02T17:30:00Z'],
    'inside a single hour' => ['2026-10-02T14:02:00Z', '2026-10-02T14:58:00Z'],
    'edge on a verdict change' => ['2026-10-02T14:00:00Z', '2026-10-02T14:10:00Z'],
    'from creation' => ['2026-10-02T09:00:00Z', '2026-10-02T18:00:00Z'],
    'crossing midnight' => ['2026-10-02T17:00:00Z', '2026-10-03T01:30:00Z'],
]);

it('reads whole hours from rollups and only the edges from raw results', function (): void {
    slaSlots($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z');
    app(BuildCheckRollups::class)->advance($this->team->id, $this->monitor->id);
    slaMarkRollup($this->monitor, '2026-10-03T09:00:00Z', 1000);

    // 08:30-09:00 and 10:00-10:30 are raw; 09:00-10:00 is the marked row, which raw results would have answered as 3600.
    expect(slaSeconds(slaBetween($this->monitor, '2026-10-03T08:30:00Z', '2026-10-03T10:30:00Z')))->toBe([1800 + 1000 + 1800, 0, 0, 0]);
});

it('uses the first rollup of a monitor for a window starting exactly at its creation', function (): void {
    $monitor = slaMonitor($this->team, '2026-10-03T08:20:00Z');

    slaSlots($monitor, '2026-10-03T08:20:00Z', '2026-10-03T09:00:00Z');
    app(BuildCheckRollups::class)->advance($this->team->id, $monitor->id);
    slaMarkRollup($monitor, '2026-10-03T08:00:00Z', 7);

    expect(slaSeconds(slaBetween($monitor, '2026-10-03T08:20:00Z', '2026-10-03T09:00:00Z')))->toBe([7, 0, 0, 0]);
});

it('combines stored hours with the raw tail not yet rolled up', function (): void {
    slaSlots($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T12:00:00Z');
    app(BuildCheckRollups::class)->advance($this->team->id, $this->monitor->id);

    $last = DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->max('hour');

    expect(CarbonImmutable::parse((string) $last)->utc()->isBefore(CarbonImmutable::parse('2026-10-03T11:00:00Z')))->toBeTrue()
        ->and(slaSeconds(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T12:00:00Z')))->toBe([14400, 0, 0, 0]);
});

it('keeps answering from rollups after raw results expire, and declares inexact edges unavailable', function (): void {
    slaSlots($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z');
    app(BuildCheckRollups::class)->advance($this->team->id, $this->monitor->id);

    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));

    expect(slaSeconds(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z')))->toBe([10800, 0, 0, 0])
        ->and(slaBetween($this->monitor, '2026-10-03T08:30:00Z', '2026-10-03T11:00:00Z')->durations)->toBeNull()
        ->and(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T10:30:00Z')->durations)->toBeNull();
});

it('declares a stored hole unavailable after raw results expire instead of treating it as unknown', function (): void {
    slaSlots($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z');
    app(BuildCheckRollups::class)->advance($this->team->id, $this->monitor->id);
    DB::table('check_rollups_hourly')
        ->where('monitor_id', $this->monitor->id)
        ->where('hour', '2026-10-03T09:00:00Z')
        ->delete();

    expect(slaSeconds(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z')))->toBe([10800, 0, 0, 0]);

    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));

    expect(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z')->durations)->toBeNull();
});

it('declares unavailable a raw period whose results or lookback are older than the oldest partition', function (): void {
    $oldest = app(ResultPartitions::class)->oldestMonth();
    $monitor = slaMonitor($this->team, $oldest->subMonths(2)->toIso8601String());

    $this->travelTo($oldest->addDays(10));

    expect(slaBetween($monitor, $oldest->subDay()->toIso8601String(), $oldest->addHour()->toIso8601String())->durations)->toBeNull()
        ->and(slaBetween($monitor, $oldest->addMinutes(5)->toIso8601String(), $oldest->addMinutes(30)->toIso8601String())->durations)->toBeNull()
        ->and(slaSeconds(slaBetween($monitor, $oldest->addDays(5)->toIso8601String(), $oldest->addDays(5)->addHour()->toIso8601String())))->toBe([0, 0, 3600, 0]);
});

it('stops reading rollups beyond the history of the current plan, at once after a downgrade', function (): void {
    slaSlots($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z');
    app(BuildCheckRollups::class)->advance($this->team->id, $this->monitor->id);

    $this->travelTo(CarbonImmutable::parse('2027-02-01T12:00:00Z'));

    expect(slaSeconds(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z')))->toBe([10800, 0, 0, 0]);

    DB::table('teams')->where('id', $this->team->id)->update(['plan' => 'free', 'trial_ends_at' => '2026-10-10T00:00:00Z']);

    expect(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T11:00:00Z')->durations)->toBeNull();
});

it('lets the earlier verdict hold while the regional work of a slot can still finish', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T09:06:00Z'));
    $regions = ['eu' => 'up', 'us' => 'up'];

    slaSlot($this->monitor, '2026-10-03T09:00:00Z', $regions);
    slaSlot($this->monitor, '2026-10-03T09:05:00Z', ['eu' => 'down', 'us' => null]);

    $running = slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T09:06:00Z');

    expect(slaSeconds($running))->toBe([360, 0, 0, 0])
        ->and($running->slaPercent())->toBe(100.0);

    // Past its deadline the same work can only end unobserved, so the partial verdict counts and ends the earlier one.
    $this->travelTo(CarbonImmutable::parse('2026-10-03T09:11:00Z'));

    expect(slaSeconds(slaBetween($this->monitor, '2026-10-03T09:00:00Z', '2026-10-03T09:11:00Z')))->toBe([300, 0, 360, 0]);
});

it('splits a window into hours that add up to the window, leaving out time before creation', function (): void {
    $monitor = slaMonitor($this->team, '2026-10-03T09:30:00Z');

    slaSlots($monitor, '2026-10-03T09:30:00Z', '2026-10-03T11:45:00Z');

    $window = new TimeInterval(CarbonImmutable::parse('2026-10-03T08:15:00Z'), CarbonImmutable::parse('2026-10-03T11:45:00Z'));
    $hours = app(TenantContext::class)->run($this->team->id, fn () => app(MonitorAvailability::class)->hourly($monitor, $window));

    expect($hours)->toHaveCount(4)
        ->and(slaSeconds($hours[0]))->toBe([0, 0, 0, 0])
        ->and($hours[0]->observedPercent())->toBeNull()
        ->and(slaSeconds($hours[1]))->toBe([2700, 0, 0, 0])
        ->and(slaSeconds($hours[2]))->toBe([3600, 0, 0, 0])
        ->and(slaSeconds($hours[3]))->toBe([1800, 0, 0, 0])
        ->and(slaSeconds(AvailabilityReport::combined($hours)))->toBe(slaSeconds(slaBetween($monitor, '2026-10-03T08:15:00Z', '2026-10-03T11:45:00Z')));
});

it('reports the hours as unavailable when their raw results are gone', function (): void {
    $oldest = app(ResultPartitions::class)->oldestMonth();
    $monitor = slaMonitor($this->team, $oldest->subMonths(2)->toIso8601String());

    $this->travelTo($oldest->addMinutes(10));

    $window = new TimeInterval($oldest->subHour(), $oldest->addMinutes(10));
    $hours = app(TenantContext::class)->run($this->team->id, fn () => app(MonitorAvailability::class)->hourly($monitor, $window));

    expect(array_map(static fn (AvailabilityReport $hour) => $hour->durations, $hours))->toBe([null, null]);
});

it('never mixes the data of another team or another monitor', function (): void {
    $foreign = Team::factory()->business()->create();
    $foreignMonitor = slaMonitor($foreign, '2026-10-02T09:00:00Z');
    $sibling = slaMonitor($this->team, '2026-10-02T09:00:00Z');

    slaSlots($foreignMonitor, '2026-10-03T08:00:00Z', '2026-10-03T10:00:00Z', ['eu' => 'down']);
    slaSlots($sibling, '2026-10-03T08:00:00Z', '2026-10-03T10:00:00Z', ['eu' => 'down']);
    slaSlots($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T10:00:00Z');
    app(BuildCheckRollups::class)->runPass();

    expect(slaSeconds(slaBetween($this->monitor, '2026-10-03T08:00:00Z', '2026-10-03T10:00:00Z')))->toBe([7200, 0, 0, 0])
        ->and(slaSeconds(slaBetween($foreignMonitor, '2026-10-03T08:00:00Z', '2026-10-03T10:00:00Z')))->toBe([0, 7200, 0, 0]);
});

it('fails closed outside a tenant context', function (): void {
    $window = new TimeInterval(CarbonImmutable::parse('2026-10-03T08:00:00Z'), CarbonImmutable::parse('2026-10-03T10:00:00Z'));

    expect(fn () => app(MonitorAvailability::class)->between($this->monitor, $window))->toThrow(MissingTenantContext::class);
});
