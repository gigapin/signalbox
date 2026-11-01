<?php

declare(strict_types=1);

/**
 * Verify metrics across calendar boundaries on PostgreSQL: month lengths, DST, year changes, exclusions spanning months,
 * periods without checks, percentile positions and repeated nightly runs.
 *
 * Every date precedes any partition the schema commits, so these results do not depend on the real month of the run.
 */

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\AvailabilityReport;
use App\Data\Metrics\TimeInterval;
use App\Data\Reports\MonthlyReportRow;
use App\Data\Reports\ReportMonth;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Metrics\MonitorAvailability;
use App\Services\Metrics\Retention\ExpiredResultPartitions;
use App\Services\Metrics\Rollups\BuildCheckRollups;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Reports\BuildMonthlyReport;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

pest()->group('docker');

/** Microseconds in one hour. */
const BOUNDARY_HOUR = 3_600_000_000;

/** Largest configurable cadence, which keeps a whole month of contiguous verdicts down to one slot per day. */
const BOUNDARY_DAILY = 86_400;

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Owning team.
 * @param CarbonImmutable $createdAt Monitor creation instant.
 * @param int $interval Cadence in seconds.
 * @return Monitor Monitor with an explicit lifetime start.
 */
function boundaryMonitor(Team $team, CarbonImmutable $createdAt, int $interval = 300): Monitor
{
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create(['interval_seconds' => $interval]));

    DB::table('monitors')->where('id', $monitor->id)->update(['created_at' => $createdAt->utc()->format('Y-m-d\TH:i:s\Z')]);

    return app(TenantContext::class)->run($team->id, fn () => $monitor->refresh());
}

/**
 * @param Monitor $monitor Owning monitor.
 * @param CarbonImmutable $slot Slot instant, also the observation instant.
 * @param string $status Observed status.
 * @param int|null $latency Measured latency in milliseconds; null when nothing could be measured.
 * @param int $interval Reserved cadence in seconds.
 */
function boundarySlot(Monitor $monitor, CarbonImmutable $slot, string $status = 'up', ?int $latency = 100, int $interval = 300): void
{
    $slotAt = $slot->utc();

    app(ResultPartitions::class)->ensure($slotAt);

    $executionId = DB::table('check_executions')->insertGetId([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'region' => 'eu',
        'slot_at' => $slotAt,
        'expires_at' => $slotAt->addSeconds($interval),
        'monitor_revision' => $monitor->execution_revision,
        'state' => 'completed',
        'available_at' => $slotAt,
        'finished_at' => $slotAt,
    ]);

    DB::table('check_results')->insert([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'execution_id' => $executionId,
        'region' => 'eu',
        'slot_at' => $slotAt,
        'monitor_revision' => $monitor->execution_revision,
        'kind' => 'observed',
        'status' => $status,
        'checked_at' => $slotAt,
        'recorded_at' => $slotAt,
        'response_time_ms' => $latency,
        'error_code' => $status === 'down' ? 'synthetic_error' : null,
        'error_message' => $status === 'down' ? 'Synthetic diagnostic.' : null,
    ]);
}

/**
 * Fills a period with contiguous up verdicts, starting early enough for any verdict lookback.
 *
 * @param Team $team Owning team.
 * @param CarbonImmutable $start Start of the period to cover.
 * @param CarbonImmutable $end End of the period to cover.
 * @return Monitor Monitor whose verdicts hold through the whole period.
 */
function boundaryCovered(Team $team, CarbonImmutable $start, CarbonImmutable $end): Monitor
{
    $first = $start->utc()->subDays(2)->startOfDay();
    $monitor = boundaryMonitor($team, $first->subHour(), BOUNDARY_DAILY);

    app(ResultPartitions::class)->ensure($first->subHour());

    for ($slot = $first; $slot->isBefore($end); $slot = $slot->addDay()) {
        boundarySlot($monitor, $slot, interval: BOUNDARY_DAILY);
    }

    return $monitor;
}

/**
 * @param Monitor $monitor Monitor to read.
 * @param CarbonImmutable $from Window start.
 * @param CarbonImmutable $to Window end.
 * @return AvailabilityReport Report calculated inside the monitor's tenant.
 */
function boundaryBetween(Monitor $monitor, CarbonImmutable $from, CarbonImmutable $to): AvailabilityReport
{
    return app(TenantContext::class)->run($monitor->team_id, fn () => app(MonitorAvailability::class)->between($monitor, new TimeInterval($from, $to)));
}

/**
 * @param AvailabilityReport $report Calculated report.
 * @return list<int> Up, down, unknown and excluded time in whole seconds.
 */
function boundarySeconds(AvailabilityReport $report): array
{
    $durations = $report->durations;

    expect($durations)->toBeInstanceOf(AvailabilityDurations::class);

    return array_map(static fn (int $value): int => intdiv($value, 1_000_000), [$durations->up, $durations->down, $durations->unknown, $durations->excluded]);
}

/**
 * @param Team $team Team of the report, with its timezone.
 * @param string $month Month as YYYY-MM.
 * @return MonthlyReportRow First row of the team's report.
 */
function boundaryReportRow(Team $team, string $month): MonthlyReportRow
{
    return app(TenantContext::class)->run($team->id, fn () => app(BuildMonthlyReport::class)->build($team, ReportMonth::parse($month, $team->timezone)))->rows[0];
}

/**
 * @param Monitor $monitor Monitor to read.
 * @param string $table Rollup table.
 * @param string $column Column ordering the rows.
 * @return list<array<string, mixed>> Stored rows without the monitor's identity, so two monitors can be compared.
 */
function boundaryRows(Monitor $monitor, string $table, string $column): array
{
    return DB::table($table)
        ->where('monitor_id', $monitor->id)
        ->orderBy($column)
        ->get()
        ->map(static function (object $row): array {
            $values = (array) $row;

            unset($values['monitor_id'], $values['team_id']);

            return $values;
        })
        ->all();
}

it('adds a whole calendar month up exactly, from rollups, after raw results are out of reach', function (string $month, string $timezone, int $hours): void {
    $team = Team::factory()->business()->create(['timezone' => $timezone]);
    $window = ReportMonth::parse($month, $timezone)->window();

    $this->travelTo($window->start);
    boundaryCovered($team, $window->start, $window->end);

    $this->travelTo($window->end->addDays(3));
    app(BuildCheckRollups::class)->runPass();

    $this->travelTo($window->end->addDays(40));

    $row = boundaryReportRow($team, $month);

    expect($row->availability->durations?->up)->toBe($hours * BOUNDARY_HOUR)
        ->and($row->availability->durations?->unknown)->toBe(0)
        ->and($row->sla())->toBe('100.00%')
        ->and($row->coverage())->toBe('100.00%');
})->with([
    '28-day February' => ['2025-02', 'UTC', 672],
    '29-day leap February' => ['2024-02', 'UTC', 696],
    '30-day month' => ['2025-04', 'UTC', 720],
    '31-day month' => ['2025-01', 'UTC', 744],
    'spring DST in Rome' => ['2025-03', 'Europe/Rome', 743],
    'autumn DST in Rome' => ['2025-10', 'Europe/Rome', 745],
    'autumn DST in New York' => ['2025-11', 'America/New_York', 721],
    'crossing the year in Tokyo' => ['2025-01', 'Asia/Tokyo', 744],
]);

it('reads a local day of 23, 24 or 25 hours identically from raw results and rollups', function (string $day, string $timezone, int $hours): void {
    $team = Team::factory()->business()->create(['timezone' => $timezone]);
    $start = (new CarbonImmutable("{$day} 00:00:00", $timezone))->utc();
    $end = (new CarbonImmutable("{$day} 00:00:00", $timezone))->addDay()->utc();

    $this->travelTo($start);

    $monitor = boundaryCovered($team, $start, $end);

    $this->travelTo($end->addDays(3));

    $raw = boundaryBetween($monitor, $start, $end);
    $split = app(TenantContext::class)->run($team->id, fn () => app(MonitorAvailability::class)->hourly($monitor, new TimeInterval($start, $end)));

    app(BuildCheckRollups::class)->runPass();

    expect($raw->durations?->up)->toBe($hours * BOUNDARY_HOUR)
        ->and($split)->toHaveCount($hours)
        ->and(boundaryBetween($monitor, $start, $end))->toEqual($raw);
})->with([
    'spring change in Rome' => ['2025-03-30', 'Europe/Rome', 23],
    'autumn change in Rome' => ['2025-10-26', 'Europe/Rome', 25],
    'spring change in New York' => ['2025-03-09', 'America/New_York', 23],
    'ordinary day' => ['2025-06-15', 'Europe/Rome', 24],
    'half-hour offset' => ['2025-06-15', 'Asia/Kolkata', 24],
]);

it('splits a verdict and its samples at the year change, into the right partition, hour and day', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = boundaryMonitor($team, CarbonImmutable::parse('2025-12-31T22:00:00Z'));

    // January 1st must be over, because only a closed day has a daily row.
    $this->travelTo(CarbonImmutable::parse('2026-01-02T03:00:00Z'));

    // Holds for two periods, so ten minutes: two in December and eight in January.
    boundarySlot($monitor, CarbonImmutable::parse('2025-12-31T23:58:00Z'), 'down', 100);
    boundarySlot($monitor, CarbonImmutable::parse('2026-01-01T00:30:00Z'), 'up', 900);

    $from = CarbonImmutable::parse('2025-12-31T23:00:00Z');
    $midnight = CarbonImmutable::parse('2026-01-01T00:00:00Z');
    $to = CarbonImmutable::parse('2026-01-01T01:00:00Z');
    $raw = boundaryBetween($monitor, $from, $to);

    app(BuildCheckRollups::class)->runPass();

    $december = DB::table('check_rollups_daily')->where('monitor_id', $monitor->id)->where('day', '2025-12-31')->first();
    $january = DB::table('check_rollups_daily')->where('monitor_id', $monitor->id)->where('day', '2026-01-01')->first();

    expect(DB::table('check_results_2025_12')->where('monitor_id', $monitor->id)->count())->toBe(1)
        ->and(DB::table('check_results_2026_01')->where('monitor_id', $monitor->id)->count())->toBe(1)
        ->and($december->down_us)->toBe(120_000_000)
        ->and($december->p50_ms)->toBe(100)
        ->and($january->p50_ms)->toBe(900)
        ->and(boundarySeconds(boundaryBetween($monitor, $from, $midnight))[1])->toBe(120)
        ->and(boundarySeconds(boundaryBetween($monitor, $midnight, $to))[1])->toBe(480)
        ->and(boundaryBetween($monitor, $from, $to))->toEqual($raw);
});

it('makes two adjacent months add up to the window that covers both, in a team timezone', function (): void {
    $team = Team::factory()->business()->create(['timezone' => 'Europe/Rome']);
    $december = ReportMonth::parse('2025-12', 'Europe/Rome')->window();
    $january = ReportMonth::parse('2026-01', 'Europe/Rome')->window();

    $this->travelTo($december->start);

    $monitor = boundaryCovered($team, $december->start, $january->end);

    boundarySlot($monitor, CarbonImmutable::parse('2025-12-31T22:59:00Z'), 'down', 100, BOUNDARY_DAILY);

    $this->travelTo($january->end->addDays(3));
    app(BuildCheckRollups::class)->runPass();

    $parts = [boundaryBetween($monitor, $december->start, $december->end), boundaryBetween($monitor, $january->start, $january->end)];
    $whole = boundaryBetween($monitor, $december->start, $january->end);

    expect(AvailabilityReport::combined($parts))->toEqual($whole)
        ->and($whole->durations?->down)->toBeGreaterThan(0);
});

it('subtracts exclusions spanning a month end once, in each month and overall, while a past pause changes nothing later', function (): void {
    $team = Team::factory()->business()->create();
    $boundary = CarbonImmutable::parse('2025-04-01T00:00:00Z');
    $from = $boundary->subDay();
    $to = $boundary->addDays(2);

    $this->travelTo($from);

    $monitor = boundaryCovered($team, $from, $to);

    DB::table('maintenance_windows')->insert([
        ['team_id' => $team->id, 'monitor_id' => $monitor->id, 'starts_at' => '2025-03-31T20:00:00Z', 'ends_at' => '2025-04-01T02:00:00Z', 'reason' => 'Synthetic maintenance.'],
        ['team_id' => $team->id, 'monitor_id' => $monitor->id, 'starts_at' => '2025-04-02T01:00:00Z', 'ends_at' => '2025-04-02T02:00:00Z', 'reason' => 'Adjacent to the pause below.'],
    ]);
    DB::table('monitor_pause_periods')->insert([
        // Overlaps the window spanning the month end, adds two hours.
        ['team_id' => $team->id, 'monitor_id' => $monitor->id, 'kind' => 'voluntary', 'started_at' => '2025-04-01T00:00:00Z', 'ended_at' => '2025-04-01T04:00:00Z'],
        // Entirely inside the two above: adds nothing.
        ['team_id' => $team->id, 'monitor_id' => $monitor->id, 'kind' => 'plan_suspension', 'started_at' => '2025-04-01T01:00:00Z', 'ended_at' => '2025-04-01T03:00:00Z'],
        // Adjacent to the second maintenance window: nothing counted twice.
        ['team_id' => $team->id, 'monitor_id' => $monitor->id, 'kind' => 'voluntary', 'started_at' => '2025-04-02T00:00:00Z', 'ended_at' => '2025-04-02T01:00:00Z'],
        // Ended before the window.
        ['team_id' => $team->id, 'monitor_id' => $monitor->id, 'kind' => 'voluntary', 'started_at' => '2025-03-29T00:00:00Z', 'ended_at' => '2025-03-29T06:00:00Z'],
    ]);

    $this->travelTo($to->addDays(3));

    $hour = 3600;
    $expect = static function () use ($monitor, $from, $boundary, $to, $hour): void {
        expect(boundarySeconds(boundaryBetween($monitor, $from, $boundary)))->toBe([20 * $hour, 0, 0, 4 * $hour])
            ->and(boundarySeconds(boundaryBetween($monitor, $boundary, $to)))->toBe([42 * $hour, 0, 0, 6 * $hour])
            ->and(boundarySeconds(boundaryBetween($monitor, $from, $to)))->toBe([62 * $hour, 0, 0, 10 * $hour])
            ->and(boundaryBetween($monitor, $from, $to)->slaPercent())->toBe(100.0);
    };

    $expect();

    app(BuildCheckRollups::class)->runPass();

    $expect();
});

it('reports a whole month without checks as unknown, never as available', function (): void {
    $team = Team::factory()->business()->create();
    $window = ReportMonth::parse('2025-04', 'UTC')->window();

    $this->travelTo($window->start);

    $monitor = boundaryMonitor($team, $window->start->subMonth());

    app(ResultPartitions::class)->ensure($window->start->subMonth());

    // Valid for two periods, so its verdict reaches eight minutes into the empty month.
    boundarySlot($monitor, $window->start->subMinutes(2));

    $this->travelTo($window->end->addDays(3));
    app(BuildCheckRollups::class)->runPass();

    $this->travelTo($window->end->addDays(40));

    $row = boundaryReportRow($team, '2025-04');

    expect(boundarySeconds($row->availability))->toBe([480, 0, 720 * 3600 - 480, 0])
        ->and($row->sla())->toBe('n/a')
        ->and($row->observed())->toBe('100.00%')
        ->and($row->coverage())->toBe('0.01%')
        ->and(DB::table('check_rollups_daily')->where('monitor_id', $monitor->id)->where('day', '2025-04-15')->value('checks'))->toBe(0)
        ->and(DB::table('check_rollups_daily')->where('monitor_id', $monitor->id)->where('day', '2025-04-15')->value('hours'))->toBe(24);
});

it('separates a gap inside a month from the time around it, and resumes at the next observation', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = boundaryMonitor($team, CarbonImmutable::parse('2025-04-10T00:00:00Z'));

    $this->travelTo(CarbonImmutable::parse('2025-04-11T00:00:00Z'));

    boundarySlot($monitor, CarbonImmutable::parse('2025-04-10T09:00:00Z'));
    boundarySlot($monitor, CarbonImmutable::parse('2025-04-10T11:00:00Z'), 'down');

    $from = CarbonImmutable::parse('2025-04-10T09:00:00Z');
    $to = CarbonImmutable::parse('2025-04-10T12:00:00Z');
    $raw = boundaryBetween($monitor, $from, $to);

    app(BuildCheckRollups::class)->runPass();

    // Ten minutes up, ten down, and the rest is neither.
    expect(boundarySeconds($raw))->toBe([600, 600, 3 * 3600 - 1200, 0])
        ->and(boundaryBetween($monitor, $from, $to))->toEqual($raw);
});

it('takes percentiles at their exact positions, per day, leaving unmeasured checks out', function (array $latencies, ?int $p50, ?int $p95, int $samples, ?float $average): void {
    $team = Team::factory()->business()->create();
    $start = CarbonImmutable::parse('2025-03-31T00:00:00Z');
    $monitor = boundaryMonitor($team, $start);

    $this->travelTo($start->addDays(2));

    foreach ($latencies as $index => $latency) {
        boundarySlot($monitor, $start->addMinutes(5 * $index), 'up', $latency);
    }

    // The sample of the next UTC day, also the next month, must stay out of this day's distribution.
    boundarySlot($monitor, CarbonImmutable::parse('2025-04-01T00:00:00Z'), 'up', 99_999);

    app(BuildCheckRollups::class)->runPass();

    $day = DB::table('check_rollups_daily')->where('monitor_id', $monitor->id)->where('day', '2025-03-31')->first();

    expect($day->p50_ms)->toBe($p50)
        ->and($day->p95_ms)->toBe($p95)
        ->and($day->latency_samples)->toBe($samples)
        ->and($day->avg_ms === null ? null : (float) $day->avg_ms)->toBe($average);
})->with([
    'single value' => [[42], 42, 42, 1, 42.0],
    'twenty values: position 10 and 19' => [range(1, 20), 10, 19, 20, 10.5],
    'twenty-one values: position 11 and 20' => [range(1, 21), 11, 20, 21, 11.0],
    'repeated values' => [[5, 5, 5, 5, 900], 5, 900, 5, 184.0],
    'zero is a measurement' => [[0, 0, 0], 0, 0, 3, 0.0],
    'unmeasured checks left out' => [[null, 10, null, 20, null], 10, 20, 2, 15.0],
    'nothing measured' => [[null, null], null, null, 0, null],
]);

it('produces the same rollups whether advanced every night or all at once, across a month end', function (): void {
    $team = Team::factory()->business()->create();
    $created = CarbonImmutable::parse('2025-03-30T00:00:00Z');
    $nightly = boundaryMonitor($team, $created);
    $atOnce = boundaryMonitor($team, $created);

    $this->travelTo($created);

    foreach ([$nightly, $atOnce] as $monitor) {
        boundarySlot($monitor, CarbonImmutable::parse('2025-03-30T12:00:00Z'), 'up', 120);
        boundarySlot($monitor, CarbonImmutable::parse('2025-03-31T23:58:00Z'), 'down', null);
        boundarySlot($monitor, CarbonImmutable::parse('2025-04-01T00:05:00Z'), 'up', 300);
        boundarySlot($monitor, CarbonImmutable::parse('2025-04-02T06:00:00Z'), 'up', 80);
    }

    $builder = app(BuildCheckRollups::class);

    foreach (['2025-03-31', '2025-04-01', '2025-04-02', '2025-04-03'] as $night) {
        $this->travelTo(CarbonImmutable::parse("{$night}T02:00:00Z"));
        $builder->advance($team->id, $nightly->id);
    }

    $builder->advance($team->id, $atOnce->id);

    expect(boundaryRows($nightly, 'check_rollups_hourly', 'hour'))->toEqual(boundaryRows($atOnce, 'check_rollups_hourly', 'hour'))
        ->and(boundaryRows($nightly, 'check_rollups_daily', 'day'))->toEqual(boundaryRows($atOnce, 'check_rollups_daily', 'day'))
        ->and(boundaryRows($nightly, 'check_rollups_daily', 'day'))->not->toBe([]);
});

it('drops a month at its exact limit, whatever its length', function (string $month, string $limit): void {
    $team = Team::factory()->business()->create();
    $start = CarbonImmutable::parse("{$month}-01T00:00:00Z");
    $monitor = boundaryMonitor($team, $start);
    $name = 'check_results_'.$start->format('Y_m');

    $this->travelTo($start);
    boundarySlot($monitor, $start->addDays(3));

    $at = CarbonImmutable::parse($limit);

    $this->travelTo($at);
    app(BuildCheckRollups::class)->runPass();

    $this->travelTo($at->subSecond());

    expect(app(ExpiredResultPartitions::class)->drop()->dropped)->toBe([]);

    $this->travelTo($at);

    $outcome = app(ExpiredResultPartitions::class)->drop();

    expect($outcome->dropped)->toBe([$name])
        ->and($outcome->blocked)->toBe([]);
})->with([
    '28-day February' => ['2025-02', '2025-04-02T00:00:00Z'],
    '29-day leap February' => ['2024-02', '2024-04-02T00:00:00Z'],
    '30-day month' => ['2025-04', '2025-06-02T00:00:00Z'],
    '31-day month before a short February' => ['2025-01', '2025-03-05T00:00:00Z'],
    '31-day month before a leap February' => ['2024-01', '2024-03-04T00:00:00Z'],
    'December, across the year' => ['2024-12', '2025-02-02T00:00:00Z'],
]);

it('stays consistent over consecutive nights: keeps, then drops once, and no partition job brings the month back', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = boundaryMonitor($team, CarbonImmutable::parse('2025-03-01T00:00:00Z'));
    $partitions = app(ResultPartitions::class);
    $month = fn (): array => array_map(static fn (CarbonImmutable $value): string => $value->format('Y-m'), $partitions->months());
    $marchRows = fn (): array => DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->where('hour', '<', '2025-04-01T00:00:00Z')->orderBy('hour')->get()->all();

    $this->travelTo(CarbonImmutable::parse('2025-03-01T00:00:00Z'));
    $partitions->ensure(CarbonImmutable::parse('2025-04-01T00:00:00Z'));
    boundarySlot($monitor, CarbonImmutable::parse('2025-03-31T23:58:00Z'), 'down');
    boundarySlot($monitor, CarbonImmutable::parse('2025-04-15T12:00:00Z'));

    $this->travelTo(CarbonImmutable::parse('2025-05-02T02:00:00Z'));

    expect(Artisan::call('metrics:nightly'))->toBe(0)
        ->and($month())->toContain('2025-03');

    $rows = $marchRows();

    $this->travelTo(CarbonImmutable::parse('2025-05-03T02:00:00Z'));

    expect(Artisan::call('metrics:nightly'))->toBe(0)
        ->and($month())->not->toContain('2025-03')
        ->and($marchRows())->toEqual($rows)
        ->and($rows)->toHaveCount(744);

    $this->travelTo(CarbonImmutable::parse('2025-05-04T02:00:00Z'));

    expect(Artisan::call('monitors:partitions'))->toBe(0)
        ->and(Artisan::call('metrics:nightly'))->toBe(0)
        ->and($month())->not->toContain('2025-03')
        ->and($month())->toContain('2025-04')
        ->and($month())->toContain('2025-07')
        ->and($marchRows())->toEqual($rows)
        ->and(boundarySeconds(boundaryBetween($monitor, CarbonImmutable::parse('2025-03-31T23:00:00Z'), CarbonImmutable::parse('2025-04-01T00:00:00Z')))[1])->toBe(120);
});
