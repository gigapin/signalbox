<?php

declare(strict_types=1);

/** Verify partition removal, its rollup verification, plan retention and heartbeat and ledger cleanup on PostgreSQL. */

use App\Data\Metrics\TimeInterval;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Metrics\MonitorAvailability;
use App\Services\Metrics\Retention\ExecutionLedgerCleanup;
use App\Services\Metrics\Retention\ExpiredResultPartitions;
use App\Services\Metrics\Retention\HeartbeatReceiptCleanup;
use App\Services\Metrics\Retention\NightlyMetricMaintenance;
use App\Services\Metrics\Retention\RollupRetention;
use App\Services\Metrics\Retention\RollupVerification;
use App\Services\Metrics\Rollups\BuildCheckRollups;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->group('docker');

/** Oldest month in these tests, safely before any partition the schema creates. */
const NIGHTLY_MONTH = '2025-03-01T00:00:00Z';

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->month = CarbonImmutable::parse(NIGHTLY_MONTH);
    $this->partitions = app(ResultPartitions::class);
    $this->partitions->ensure($this->month);
    $this->travelTo($this->month->addDays(2));
    $this->team = Team::factory()->business()->create();
    $this->monitor = nightlyMonitor($this->team, '2025-03-01T09:00:00Z');
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Owning team.
 * @param string $createdAt Monitor creation instant.
 * @return Monitor Monitor with a five-minute cadence.
 */
function nightlyMonitor(Team $team, string $createdAt): Monitor
{
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create(['interval_seconds' => 300]));

    DB::table('monitors')->where('id', $monitor->id)->update(['created_at' => $createdAt]);

    return app(TenantContext::class)->run($team->id, fn () => $monitor->refresh());
}

/**
 * @param Monitor $monitor Owning monitor.
 * @param string $slot Slot instant, also the observation instant.
 * @param string $state Execution state; only completed work has a result.
 * @param string $status Observed status of completed work.
 * @return int Ledger identity.
 */
function nightlySlot(Monitor $monitor, string $slot, string $state = 'completed', string $status = 'up'): int
{
    $slotAt = CarbonImmutable::parse($slot)->utc();
    $completed = $state === 'completed';

    app(ResultPartitions::class)->ensure($slotAt);

    $id = DB::table('check_executions')->insertGetId([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'region' => 'eu',
        'slot_at' => $slotAt,
        'expires_at' => $slotAt->addSeconds(300),
        'monitor_revision' => $monitor->execution_revision,
        'state' => $state,
        'available_at' => $slotAt,
        'finished_at' => $completed ? $slotAt : null,
    ]);

    if ($completed) {
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
            'response_time_ms' => 120,
            'error_code' => $status === 'down' ? 'synthetic_error' : null,
            'error_message' => $status === 'down' ? 'Synthetic diagnostic.' : null,
        ]);
    }

    return $id;
}

/**
 * Writes two slots without rolling them up, then moves to the first instant the month may expire.
 *
 * @param Monitor $monitor Monitor to fill.
 */
function nightlyExpiredMonth(Monitor $monitor): void
{
    nightlySlot($monitor, '2025-03-01T09:05:00Z');
    nightlySlot($monitor, '2025-03-10T12:00:00Z', status: 'down');

    test()->travelTo(CarbonImmutable::parse('2025-05-03T00:00:00Z'));
}

/** @return array<string, array<string, int>> Months kept by one removal pass. */
function nightlyDropBlocked(): array
{
    return app(ExpiredResultPartitions::class)->drop()->blocked;
}

/** @return list<string> Existing month identifiers. */
function nightlyMonths(): array
{
    return array_map(static fn (CarbonImmutable $month): string => $month->format('Y-m'), app(ResultPartitions::class)->months());
}

it('keeps a month inside the readable window plus the verdict lookback', function (string $now, bool $dropped): void {
    nightlySlot($this->monitor, '2025-03-01T09:05:00Z');
    $this->travelTo(CarbonImmutable::parse($now));
    app(BuildCheckRollups::class)->runPass();

    $outcome = app(ExpiredResultPartitions::class)->drop();

    expect($outcome->dropped)->toBe($dropped ? ['check_results_2025_03'] : [])
        ->and($outcome->blocked)->toBe([])
        ->and(in_array('2025-03', nightlyMonths(), true))->toBe(!$dropped);
})->with([
    // The month ends on April 1st: 30 readable days reach May 1st and the 48-hour lookback May 3rd.
    'month just ended' => ['2025-04-02T00:00:00Z', false],
    'thirty days after its end' => ['2025-05-01T00:00:00Z', false],
    'one second before the limit' => ['2025-05-02T23:59:59Z', false],
    'exactly at the limit' => ['2025-05-03T00:00:00Z', true],
]);

it('drops a verified month, keeps its rollups and removes its ledger rows', function (): void {
    nightlyExpiredMonth($this->monitor);

    expect(Artisan::call('metrics:nightly'))->toBe(0);

    $window = new TimeInterval(CarbonImmutable::parse('2025-03-10T12:00:00Z'), CarbonImmutable::parse('2025-03-10T13:00:00Z'));

    expect(nightlyMonths())->not->toContain('2025-03')
        ->and(DB::table('check_executions')->where('monitor_id', $this->monitor->id)->count())->toBe(0)
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->where('hour', '2025-03-10T12:00:00Z')->value('failures'))->toBe(1)
        ->and(DB::table('check_rollups_daily')->where('monitor_id', $this->monitor->id)->where('day', '2025-03-10')->value('failures'))->toBe(1)
        ->and(app(TenantContext::class)->run($this->team->id, fn () => app(MonitorAvailability::class)->between($this->monitor, $window))->durations?->down)->toBe(600_000_000);
});

it('is idempotent when run again', function (): void {
    nightlyExpiredMonth($this->monitor);

    expect(Artisan::call('metrics:nightly'))->toBe(0);

    $rows = DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->count();

    expect(Artisan::call('metrics:nightly'))->toBe(0)
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->count())->toBe($rows)
        ->and(nightlyMonths())->not->toContain('2025-03');
});

it('keeps the month and reports why when its rollups are not safe', function (string $problem, Closure $damage): void {
    nightlyExpiredMonth($this->monitor);
    app(BuildCheckRollups::class)->runPass();
    $damage($this->monitor);

    expect(nightlyDropBlocked()['2025-03'])->toHaveKey($problem)
        ->and(nightlyMonths())->toContain('2025-03')
        ->and(DB::table('check_results')->where('monitor_id', $this->monitor->id)->count())->toBe(2);
})->with([
    'rollups behind' => ['rollups_behind', fn (Monitor $monitor) => DB::table('check_rollup_cursors')->where('monitor_id', $monitor->id)->update(['hourly_through' => '2025-04-01T00:00:00Z'])],
    'no rollups at all' => ['rollups_behind', fn (Monitor $monitor) => DB::table('check_rollup_cursors')->where('monitor_id', $monitor->id)->delete()],
    'missing hour' => ['hours_missing_or_incomplete', fn (Monitor $monitor) => DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->where('hour', '2025-03-05T00:00:00Z')->delete()],
    'durations not adding up' => ['hours_missing_or_incomplete', fn (Monitor $monitor) => DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->where('hour', '2025-03-05T00:00:00Z')->update(['unknown_us' => 1])],
    'first hour durations not adding up' => ['hours_missing_or_incomplete', fn (Monitor $monitor) => DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->where('hour', '2025-03-01T09:00:00Z')->update(['up_us' => 1])],
    'different failure count' => ['hours_differ_from_raw', fn (Monitor $monitor) => DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->where('hour', '2025-03-10T12:00:00Z')->update(['failures' => 0])],
    'different latency sum' => ['hours_differ_from_raw', fn (Monitor $monitor) => DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->where('hour', '2025-03-10T12:00:00Z')->update(['latency_sum_ms' => 1])],
    'missing day' => ['days_differ_from_hours', fn (Monitor $monitor) => DB::table('check_rollups_daily')->where('monitor_id', $monitor->id)->where('day', '2025-03-10')->delete()],
    'different day' => ['days_differ_from_hours', fn (Monitor $monitor) => DB::table('check_rollups_daily')->where('monitor_id', $monitor->id)->where('day', '2025-03-10')->update(['checks' => 99])],
]);

it('keeps the month while work of that month can still be recovered', function (): void {
    nightlyExpiredMonth($this->monitor);
    nightlySlot($this->monitor, '2025-03-20T12:00:00Z', 'pending');
    DB::table('check_rollup_cursors')->insertOrIgnore(['team_id' => $this->team->id, 'monitor_id' => $this->monitor->id]);

    expect(nightlyDropBlocked()['2025-03'])->toHaveKey('open_work')
        ->and(nightlyMonths())->toContain('2025-03');
});

it('exits with failure and keeps the month when verification fails', function (): void {
    nightlyExpiredMonth($this->monitor);
    app(BuildCheckRollups::class)->runPass();
    DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->where('hour', '2025-03-10T12:00:00Z')->update(['checks' => 50]);

    expect(Artisan::call('metrics:nightly'))->toBe(1)
        ->and(Artisan::output())->toContain('Partition 2025-03 kept')
        ->and(nightlyMonths())->toContain('2025-03')
        ->and(DB::table('check_executions')->where('monitor_id', $this->monitor->id)->count())->toBe(2);
});

it('does not drop a later expired month while an older one is kept', function (): void {
    $this->partitions->ensure($this->month->addMonth());
    nightlyExpiredMonth($this->monitor);
    nightlySlot($this->monitor, '2025-04-10T12:00:00Z');
    $this->travelTo(CarbonImmutable::parse('2025-06-03T00:00:00Z'));
    app(BuildCheckRollups::class)->runPass();
    DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->where('hour', '2025-03-10T12:00:00Z')->update(['checks' => 50]);

    $blocked = nightlyDropBlocked();

    expect($blocked['2025-03'])->toHaveKey('hours_differ_from_raw')
        ->and($blocked['2025-04'])->toBe(['older_month_kept' => 1])
        ->and(nightlyMonths())->toContain('2025-03')
        ->and(nightlyMonths())->toContain('2025-04');
});

it('checks every tenant before dropping a shared month', function (): void {
    $foreign = Team::factory()->business()->create();
    $foreignMonitor = nightlyMonitor($foreign, '2025-03-01T09:00:00Z');

    nightlySlot($foreignMonitor, '2025-03-02T09:00:00Z');
    nightlyExpiredMonth($this->monitor);
    app(BuildCheckRollups::class)->runPass();
    DB::table('check_rollups_hourly')->where('monitor_id', $foreignMonitor->id)->where('hour', '2025-03-02T09:00:00Z')->update(['checks' => 7]);

    expect(nightlyDropBlocked())->toHaveKey('2025-03');
});

it('reports only counts when a month is kept', function (): void {
    nightlyExpiredMonth($this->monitor);

    $problems = app(RollupVerification::class)->problems($this->month, CarbonImmutable::parse('2025-04-03T00:00:00Z'));

    expect($problems)->not->toBe([]);

    foreach ($problems as $count) {
        expect($count)->toBeInt();
    }
});

it('gives up on a busy partition within the lock timeout, keeping it and the caller timeout', function (): void {
    // Only partitions committed by the migrations are visible to a second session; the 2025 months exist inside the test transaction.
    $committed = collect($this->partitions->months())->last();
    $name = 'check_results_'.$committed->format('Y_m');

    config(['database.connections.nightly_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('nightly_probe');
    $timeout = DB::scalar("SELECT current_setting('lock_timeout')");

    try {
        $probe->beginTransaction();
        $probe->statement("LOCK TABLE {$name} IN ACCESS SHARE MODE");

        expect(fn () => $this->partitions->drop($committed))->toThrow(QueryException::class)
            ->and(DB::scalar("SELECT current_setting('lock_timeout')"))->toBe($timeout)
            ->and(nightlyMonths())->toContain($committed->format('Y-m'));
    } finally {
        $probe->rollBack();
        DB::purge('nightly_probe');
    }
});

it('removes rollups beyond the plan history but never those of a month still holding raw results', function (): void {
    nightlyExpiredMonth($this->monitor);
    $this->travelTo(CarbonImmutable::parse('2025-08-01T00:00:00Z'));
    app(BuildCheckRollups::class)->runPass();
    DB::table('teams')->where('id', $this->team->id)->update(['plan' => 'free', 'trial_started_at' => '2025-03-01T00:00:00Z', 'trial_ends_at' => '2025-03-02T00:00:00Z']);

    $before = DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->count();

    // The month is beyond the 90 days of the Free plan, but its raw results still exist.
    expect(app(RollupRetention::class)->prune())->toBe(0)
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->count())->toBe($before);

    $this->partitions->drop($this->month);

    expect(app(RollupRetention::class)->prune())->toBeGreaterThan(0)
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->min('hour'))->not->toBeNull()
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->where('hour', '<', '2025-05-03T00:00:00Z')->count())->toBe(0)
        ->and(DB::table('check_rollups_daily')->where('monitor_id', $this->monitor->id)->where('day', '<', '2025-05-03')->count())->toBe(0);
});

it('applies each plan history to its own team only, and an upgrade recovers nothing', function (): void {
    $free = Team::factory()->create(['plan' => 'free', 'trial_started_at' => '2025-03-01T00:00:00Z', 'trial_ends_at' => '2025-03-02T00:00:00Z']);
    $freeMonitor = nightlyMonitor($free, '2025-03-01T09:00:00Z');

    nightlyExpiredMonth($this->monitor);
    $this->travelTo(CarbonImmutable::parse('2025-08-01T00:00:00Z'));
    app(BuildCheckRollups::class)->runPass();
    $this->partitions->drop($this->month);
    app(RollupRetention::class)->prune();

    $old = '2025-03-05T00:00:00Z';

    expect(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->where('hour', $old)->exists())->toBeTrue()
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $freeMonitor->id)->where('hour', $old)->exists())->toBeFalse();

    DB::table('teams')->where('id', $free->id)->update(['plan' => 'business']);
    app(RollupRetention::class)->prune();

    $window = new TimeInterval(CarbonImmutable::parse($old), CarbonImmutable::parse('2025-03-05T01:00:00Z'));

    expect(app(TenantContext::class)->run($free->id, fn () => app(MonitorAvailability::class)->between($freeMonitor, $window))->durations)->toBeNull();
});

it('keeps exactly the receipts that a slot still in validity can read', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-03-31T00:00:00Z'));
    $state = DB::table('monitor_heartbeats')->insertGetId([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'generation' => 3,
        'started_at' => '2025-02-01T00:00:00Z',
    ]);
    $receipt = fn (int $generation, string $at) => DB::table('heartbeat_receipts')->insert([
        'team_id' => $this->team->id,
        'monitor_heartbeat_id' => $state,
        'generation' => $generation,
        'received_at' => $at,
    ]);

    $receipt(2, '2025-02-20T00:00:00Z');
    $receipt(2, '2025-03-30T00:00:00Z');
    $receipt(3, '2025-02-25T00:00:00Z');
    $receipt(3, '2025-02-26T00:00:00Z');
    $receipt(3, '2025-03-02T00:00:00Z');
    $receipt(3, '2025-03-30T00:00:00Z');

    // The cutoff is March 1st: only the newest older receipt of the current generation answers later slots.
    expect(app(HeartbeatReceiptCleanup::class)->prune())->toBe(2)
        ->and(DB::table('heartbeat_receipts')->where('monitor_heartbeat_id', $state)->orderBy('generation')->orderBy('received_at')->pluck('received_at')->map(fn ($at) => CarbonImmutable::parse($at)->utc()->format('m-d'))->all())
        ->toBe(['03-30', '02-26', '03-02', '03-30']);
});

it('removes all older receipts of an earlier generation and is idempotent', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-03-31T00:00:00Z'));
    $state = DB::table('monitor_heartbeats')->insertGetId([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'generation' => 2,
        'started_at' => '2025-02-01T00:00:00Z',
    ]);

    DB::table('heartbeat_receipts')->insert([
        'team_id' => $this->team->id,
        'monitor_heartbeat_id' => $state,
        'generation' => 1,
        'received_at' => '2025-02-20T00:00:00Z',
    ]);

    expect(app(HeartbeatReceiptCleanup::class)->prune())->toBe(1)
        ->and(app(HeartbeatReceiptCleanup::class)->prune())->toBe(0);
});

it('keeps open ledger work and rows with retained results', function (): void {
    $this->partitions->ensure($this->month->subMonth());
    nightlySlot($this->monitor, '2025-03-05T00:00:00Z');
    $open = nightlySlot($this->monitor, '2025-02-05T00:00:00Z', 'pending');

    DB::table('check_executions')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'region' => 'eu',
        'slot_at' => '2025-02-06T00:00:00Z',
        'expires_at' => '2025-02-06T00:05:00Z',
        'monitor_revision' => $this->monitor->execution_revision,
        'state' => 'cancelled',
        'available_at' => '2025-02-06T00:00:00Z',
        'finished_at' => '2025-02-06T00:00:00Z',
    ]);

    $this->partitions->drop($this->month->subMonth());

    expect(app(ExecutionLedgerCleanup::class)->prune())->toBe(1)
        ->and(DB::table('check_executions')->where('id', $open)->exists())->toBeTrue()
        ->and(DB::table('check_results')->where('monitor_id', $this->monitor->id)->count())->toBe(1);
});

it('continues with the other steps when one fails, and reports it', function (): void {
    nightlyExpiredMonth($this->monitor);

    // The retention services are final, and only rollup retention reads teams through the model during a run.
    Team::retrieved(static function (): never {
        throw new RuntimeException('Synthetic failure.');
    });

    $report = app(NightlyMetricMaintenance::class)->run();

    expect($report->failedSteps)->toBe(['rollup_retention'])
        ->and($report->succeeded())->toBeFalse()
        ->and($report->partitions->dropped)->toBe(['check_results_2025_03']);
});

it('leaves availability of recent windows untouched after a full run', function (): void {
    // Production months are contiguous: a gap would make the window's verdict lookback unavailable.
    $this->partitions->ensure($this->month->addMonth());
    nightlyExpiredMonth($this->monitor);
    nightlySlot($this->monitor, '2025-05-02T10:00:00Z');
    nightlySlot($this->monitor, '2025-05-02T10:05:00Z');

    $window = new TimeInterval(CarbonImmutable::parse('2025-05-02T10:00:00Z'), CarbonImmutable::parse('2025-05-02T10:10:00Z'));
    $read = fn () => app(TenantContext::class)->run($this->team->id, fn () => app(MonitorAvailability::class)->between($this->monitor, $window))->durations?->up;
    $before = $read();

    Artisan::call('metrics:nightly');

    expect($read())->toBe($before)
        ->and($before)->toBe(600_000_000)
        ->and(Str::of(implode(',', nightlyMonths()))->contains('2025-03'))->toBeFalse();
});
