<?php

declare(strict_types=1);

/** Verify rollup construction, closure, idempotent rebuilding, retention boundaries and tenancy on PostgreSQL. */

use App\Exceptions\MissingTenantContext;
use App\Exceptions\RollupSourceUnavailable;
use App\Models\CheckRollupDaily;
use App\Models\CheckRollupHourly;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Metrics\Rollups\BuildCheckRollups;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedTeamFixture;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    $this->team = Team::factory()->business()->create();
    $this->monitor = rollupMonitor($this->team, '2026-10-02T09:00:00Z');
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Owning team.
 * @param string $createdAt Monitor creation instant.
 * @return Monitor Monitor with a five-minute cadence and an explicit lifetime start.
 */
function rollupMonitor(Team $team, string $createdAt): Monitor
{
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create(['interval_seconds' => 300]));

    DB::table('monitors')->where('id', $monitor->id)->update(['created_at' => $createdAt]);

    return app(TenantContext::class)->run($team->id, fn () => $monitor->refresh());
}

/**
 * Inserts a real ledger and result pair through the composite foreign keys, into the partition of the slot.
 *
 * @param Monitor $monitor Owning monitor.
 * @param string $slot Slot instant.
 * @param array<string, array{0: string, 1: int, 2: int|null}|null> $regions Status, observation offset in seconds and latency per region; null records missed work.
 * @param int $interval Reserved cadence in seconds.
 */
function rollupSlot(Monitor $monitor, string $slot, array $regions, int $interval = 300): void
{
    $slotAt = CarbonImmutable::parse($slot)->utc();

    app(ResultPartitions::class)->ensure($slotAt);

    foreach ($regions as $region => $observation) {
        $recordedAt = $slotAt->addSeconds($observation[1] ?? 0)->format('Y-m-d\TH:i:s.u\Z');
        $executionId = DB::table('check_executions')->insertGetId([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'region' => $region,
            'slot_at' => $slotAt,
            'expires_at' => $slotAt->addSeconds($interval),
            'monitor_revision' => $monitor->execution_revision,
            'state' => $observation === null ? 'missed' : 'completed',
            'available_at' => $slotAt,
            'finished_at' => $recordedAt,
        ]);
        $failed = $observation === null || $observation[0] === 'down';

        DB::table('check_results')->insert([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'execution_id' => $executionId,
            'region' => $region,
            'slot_at' => $slotAt,
            'monitor_revision' => $monitor->execution_revision,
            'kind' => $observation === null ? 'missed' : 'observed',
            'status' => $observation[0] ?? 'unknown',
            'checked_at' => $observation === null ? null : $recordedAt,
            'recorded_at' => $recordedAt,
            'response_time_ms' => $observation[2] ?? null,
            'error_code' => $failed ? 'synthetic_error' : null,
            'error_message' => $failed ? 'Synthetic diagnostic.' : null,
        ]);
    }
}

/**
 * @param Monitor $monitor Monitor to read.
 * @param string $hour UTC hour.
 * @return CheckRollupHourly Stored hour, read through the tenant boundary.
 */
function rollupHour(Monitor $monitor, string $hour): CheckRollupHourly
{
    return app(TenantContext::class)->run($monitor->team_id, fn () => CheckRollupHourly::query()
        ->where('monitor_id', $monitor->id)
        ->where('hour', CarbonImmutable::parse($hour))
        ->firstOrFail());
}

/**
 * @param Monitor $monitor Monitor to read.
 * @param string $day UTC day.
 * @return CheckRollupDaily Stored day, read through the tenant boundary.
 */
function rollupDay(Monitor $monitor, string $day): CheckRollupDaily
{
    return app(TenantContext::class)->run($monitor->team_id, fn () => CheckRollupDaily::query()
        ->where('monitor_id', $monitor->id)
        ->where('day', $day)
        ->firstOrFail());
}

/**
 * @param Monitor $monitor Monitor to advance.
 * @return int Hours written.
 */
function rollupAdvance(Monitor $monitor): int
{
    return app(BuildCheckRollups::class)->advance($monitor->team_id, $monitor->id);
}

/** @return array<string, list<object>> Raw rows of both rollup levels, for byte-level idempotence comparisons. */
function rollupSnapshot(): array
{
    return [
        'hourly' => DB::table('check_rollups_hourly')->orderBy('monitor_id')->orderBy('hour')->get()->all(),
        'daily' => DB::table('check_rollups_daily')->orderBy('monitor_id')->orderBy('day')->get()->all(),
    ];
}

it('writes exact durations, counts and latency for each monitored hour', function (): void {
    rollupSlot($this->monitor, '2026-10-02T10:00:00Z', ['eu' => ['up', 1, 100]]);
    rollupSlot($this->monitor, '2026-10-02T10:05:00Z', ['eu' => ['down', 2, 300]]);
    rollupSlot($this->monitor, '2026-10-02T10:10:00Z', ['eu' => null]);

    rollupAdvance($this->monitor);

    $empty = rollupHour($this->monitor, '2026-10-02T09:00:00Z');
    $hour = rollupHour($this->monitor, '2026-10-02T10:00:00Z');

    expect($empty->checks)->toBe(0)
        ->and($empty->unknown_us)->toBe(3_600_000_000)
        ->and($empty->uptime_ratio)->toBeNull()
        ->and($empty->avg_ms)->toBeNull()
        ->and($empty->p95_ms)->toBeNull()
        ->and($empty->latency_counts)->toBe([]);

    expect($hour->checks)->toBe(3)
        ->and($hour->failures)->toBe(1)
        ->and($hour->unknown_checks)->toBe(1)
        ->and($hour->up_us)->toBe(301_000_000)
        ->and($hour->down_us)->toBe(598_000_000)
        ->and($hour->unknown_us)->toBe(2_701_000_000)
        ->and($hour->excluded_us)->toBe(0)
        ->and($hour->uptime_ratio)->toBe(301 / 899)
        ->and($hour->latency_samples)->toBe(2)
        ->and($hour->latency_sum_ms)->toBe(400)
        ->and($hour->avg_ms)->toBe(200.0)
        ->and($hour->p50_ms)->toBe(100)
        ->and($hour->p95_ms)->toBe(300);
});

it('does not derive uptime from check counts', function (): void {
    // One failed check out of two, but the failure holds for far longer than the success.
    rollupSlot($this->monitor, '2026-10-02T10:00:00Z', ['eu' => ['up', 0, 10]], 60);
    rollupSlot($this->monitor, '2026-10-02T10:01:00Z', ['eu' => ['down', 0, 10]], 1800);

    rollupAdvance($this->monitor);

    $hour = rollupHour($this->monitor, '2026-10-02T10:00:00Z');

    expect($hour->up_us)->toBe(60_000_000)
        ->and($hour->down_us)->toBe(3_540_000_000)
        ->and($hour->uptime_ratio)->toBe(60 / 3600);
});

it('applies regional agreement by the executions reserved for the slot', function (): void {
    rollupSlot($this->monitor, '2026-10-02T10:00:00Z', ['eu' => ['up', 1, 10], 'us' => ['up', 3, 30]]);
    rollupSlot($this->monitor, '2026-10-02T10:05:00Z', ['eu' => ['up', 0, 10], 'us' => ['down', 2, 30]]);
    rollupSlot($this->monitor, '2026-10-02T10:10:00Z', ['eu' => ['up', 0, 10], 'us' => null]);

    rollupAdvance($this->monitor);

    $hour = rollupHour($this->monitor, '2026-10-02T10:00:00Z');

    // Up only from the later observation of the first slot until the discordant second slot is known.
    expect($hour->up_us)->toBe(299_000_000)
        ->and($hour->down_us)->toBe(0)
        ->and($hour->checks)->toBe(6)
        ->and($hour->failures)->toBe(1)
        ->and($hour->unknown_checks)->toBe(1)
        ->and($hour->latency_samples)->toBe(5);
});

it('carries a verdict across hour, day and month boundaries through separate transactions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01T05:00:00Z'));
    $monitor = rollupMonitor($this->team, '2026-10-31T22:00:00Z');

    rollupSlot($monitor, '2026-10-31T23:58:00Z', ['eu' => ['up', 0, 10]]);

    rollupAdvance($monitor);

    expect(rollupHour($monitor, '2026-10-31T23:00:00Z')->up_us)->toBe(120_000_000)
        ->and(rollupHour($monitor, '2026-11-01T00:00:00Z')->up_us)->toBe(480_000_000)
        ->and(rollupHour($monitor, '2026-11-01T00:00:00Z')->checks)->toBe(0)
        ->and(rollupDay($monitor, '2026-10-31')->up_us)->toBe(120_000_000)
        ->and(rollupDay($monitor, '2026-10-31')->hours)->toBe(2);
});

it('subtracts merged pauses, suspensions and maintenance from the time split', function (): void {
    rollupSlot($this->monitor, '2026-10-02T10:00:00Z', ['eu' => ['up', 0, 10]]);
    DB::table('maintenance_windows')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'starts_at' => '2026-10-02T10:02:00Z',
        'ends_at' => '2026-10-02T10:20:00Z',
        'reason' => 'Synthetic maintenance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('monitor_pause_periods')->insert([
        ['team_id' => $this->team->id, 'monitor_id' => $this->monitor->id, 'kind' => 'voluntary', 'started_at' => '2026-10-02T10:10:00Z', 'ended_at' => '2026-10-02T10:30:00Z'],
        ['team_id' => $this->team->id, 'monitor_id' => $this->monitor->id, 'kind' => 'plan_suspension', 'started_at' => '2026-10-02T10:50:00Z', 'ended_at' => null],
    ]);

    rollupAdvance($this->monitor);

    $hour = rollupHour($this->monitor, '2026-10-02T10:00:00Z');

    expect($hour->up_us)->toBe(120_000_000)
        ->and($hour->excluded_us)->toBe(38 * 60_000_000)
        ->and($hour->unknown_us)->toBe(20 * 60_000_000)
        ->and($hour->up_us + $hour->down_us + $hour->unknown_us + $hour->excluded_us)->toBe(3_600_000_000)
        ->and(rollupHour($this->monitor, '2026-10-02T11:00:00Z')->excluded_us)->toBe(3_600_000_000);
});

it('starts durations at monitor creation and marks the partial first day', function (): void {
    $monitor = rollupMonitor($this->team, '2026-10-01T22:30:00Z');

    rollupAdvance($monitor);

    expect(rollupHour($monitor, '2026-10-01T22:00:00Z')->unknown_us)->toBe(1_800_000_000)
        ->and(rollupDay($monitor, '2026-10-01')->hours)->toBe(2)
        ->and(rollupDay($monitor, '2026-10-01')->unknown_us)->toBe(5_400_000_000)
        ->and(rollupDay($monitor, '2026-10-02')->hours)->toBe(24);
});

it('derives daily percentiles and means from merged frequencies of unequal hours', function (): void {
    foreach (range(0, 8) as $minute) {
        rollupSlot($this->monitor, sprintf('2026-10-02T10:%02d:00Z', $minute * 5), ['eu' => ['up', 0, 10]]);
    }

    rollupSlot($this->monitor, '2026-10-02T11:00:00Z', ['eu' => ['up', 0, 1000]]);

    rollupAdvance($this->monitor);

    $day = rollupDay($this->monitor, '2026-10-02');
    $first = rollupHour($this->monitor, '2026-10-02T10:00:00Z');
    $second = rollupHour($this->monitor, '2026-10-02T11:00:00Z');

    // A mean of the hourly values would claim 505 ms for both the mean and the median.
    expect($first->p50_ms)->toBe(10)
        ->and($second->p50_ms)->toBe(1000)
        ->and($day->latency_samples)->toBe(10)
        ->and($day->latency_sum_ms)->toBe(1090)
        ->and($day->avg_ms)->toBe(109.0)
        ->and($day->p50_ms)->toBe(10)
        ->and($day->p95_ms)->toBe(1000)
        ->and($day->latency_counts)->toBe([10 => 9, 1000 => 1]);
});

it('does not roll up an hour that can still change', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:06:59Z'));

    rollupAdvance($this->monitor);
    expect(DB::table('check_rollups_hourly')->where('hour', '2026-10-03T11:00:00Z')->exists())->toBeFalse();

    // Exactly one interval plus the commit margin after the end of the hour, the hour closes.
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:07:00Z'));

    rollupAdvance($this->monitor);
    expect(DB::table('check_rollups_hourly')->where('hour', '2026-10-03T11:00:00Z')->exists())->toBeTrue();
});

it('holds the cursor while an execution of an earlier hour can still finish', function (): void {
    $slot = CarbonImmutable::parse('2026-10-03T10:30:00Z');

    DB::table('check_executions')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'region' => 'eu',
        'slot_at' => $slot,
        'expires_at' => now()->addMinute(),
        'monitor_revision' => $this->monitor->execution_revision,
        'state' => 'pending',
        'available_at' => $slot,
    ]);

    rollupAdvance($this->monitor);

    expect(DB::table('check_rollups_hourly')->where('hour', '2026-10-03T09:00:00Z')->exists())->toBeTrue()
        ->and(DB::table('check_rollups_hourly')->where('hour', '2026-10-03T10:00:00Z')->exists())->toBeFalse();

    // Once the same work can no longer be acquired, its absence is final and the hour closes.
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:04:00Z'));

    rollupAdvance($this->monitor);

    expect(rollupHour($this->monitor, '2026-10-03T10:00:00Z')->checks)->toBe(1)
        ->and(rollupHour($this->monitor, '2026-10-03T10:00:00Z')->unknown_checks)->toBe(1);
});

it('produces identical rows when advanced again, and writes nothing new', function (): void {
    rollupSlot($this->monitor, '2026-10-02T10:00:00Z', ['eu' => ['up', 1, 100]]);

    $written = rollupAdvance($this->monitor);
    $before = rollupSnapshot();

    expect($written)->toBe(26)
        ->and(rollupAdvance($this->monitor))->toBe(0)
        ->and(rollupSnapshot())->toEqual($before);
});

it('rebuilds a range to identical rows, replacing rows that drifted', function (): void {
    app(ResultPartitions::class)->ensure(CarbonImmutable::parse('2026-09-15T00:00:00Z'));
    rollupSlot($this->monitor, '2026-10-02T10:00:00Z', ['eu' => ['up', 1, 100]]);
    rollupSlot($this->monitor, '2026-10-02T23:00:00Z', ['eu' => ['down', 1, 200]]);
    rollupAdvance($this->monitor);

    $before = rollupSnapshot();

    DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->update(['checks' => 99, 'unknown_us' => 0]);
    DB::table('check_rollups_daily')->where('monitor_id', $this->monitor->id)->update(['checks' => 99]);

    $hours = app(BuildCheckRollups::class)->rebuild(
        $this->team->id,
        $this->monitor->id,
        CarbonImmutable::parse('2026-10-02T09:00:00Z'),
        CarbonImmutable::parse('2026-10-03T11:00:00Z'),
    );

    expect($hours)->toBe(26)
        ->and(rollupSnapshot())->toEqual($before);
});

it('does not rebuild beyond the rolled up hours', function (): void {
    app(ResultPartitions::class)->ensure(CarbonImmutable::parse('2026-09-15T00:00:00Z'));
    rollupAdvance($this->monitor);

    $hours = app(BuildCheckRollups::class)->rebuild(
        $this->team->id,
        $this->monitor->id,
        CarbonImmutable::parse('2026-10-03T10:00:00Z'),
        CarbonImmutable::parse('2026-10-04T00:00:00Z'),
    );

    expect($hours)->toBe(1)
        ->and(DB::table('check_rollups_hourly')->where('hour', '2026-10-03T11:00:00Z')->exists())->toBeFalse();
});

it('refuses a rebuild whose raw results are no longer retained, leaving rollups intact', function (): void {
    $oldest = app(ResultPartitions::class)->oldestMonth();
    $before = rollupSnapshot();

    expect(fn () => app(BuildCheckRollups::class)->rebuild($this->team->id, $this->monitor->id, $oldest, $oldest->addDay()))
        ->toThrow(RollupSourceUnavailable::class)
        ->and(rollupSnapshot())->toEqual($before);
});

it('reports the oldest retained month and starts older monitors there', function (): void {
    $oldest = app(ResultPartitions::class)->oldestMonth();
    $monitor = rollupMonitor($this->team, $oldest->subMonths(6)->toIso8601String());

    $this->travelTo($oldest->addHours(3)->addMinutes(30));

    rollupAdvance($monitor);

    expect($oldest->day)->toBe(1)
        ->and($oldest->hour)->toBe(0)
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->min('hour'))->not->toBeNull()
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $monitor->id)->where('hour', '<', $oldest->format('Y-m-d\TH:i:s\Z'))->exists())->toBeFalse();
});

it('keeps tenants separate in reads, source data and writes', function (): void {
    $foreignTeam = Team::factory()->business()->create();
    $foreign = rollupMonitor($foreignTeam, '2026-10-02T09:00:00Z');

    rollupSlot($this->monitor, '2026-10-02T10:00:00Z', ['eu' => ['up', 0, 10]]);
    rollupSlot($foreign, '2026-10-02T10:00:00Z', ['eu' => ['down', 0, 500]]);

    expect(app(BuildCheckRollups::class)->runPass()->failed)->toBe(0);

    $visible = app(TenantContext::class)->run($this->team->id, fn () => CheckRollupHourly::query()->pluck('monitor_id')->unique()->values()->all());

    expect($visible)->toBe([$this->monitor->id])
        ->and(rollupHour($this->monitor, '2026-10-02T10:00:00Z')->failures)->toBe(0)
        ->and(rollupHour($foreign, '2026-10-02T10:00:00Z')->failures)->toBe(1)
        ->and(rollupHour($foreign, '2026-10-02T10:00:00Z')->p50_ms)->toBe(500)
        ->and(fn () => CheckRollupHourly::query()->get())->toThrow(MissingTenantContext::class)
        ->and(fn () => CheckRollupDaily::query()->get())->toThrow(MissingTenantContext::class);

    // A forged team for an existing monitor finds nothing to advance.
    expect(app(BuildCheckRollups::class)->advance($foreignTeam->id, $this->monitor->id))->toBe(0);
});

it('rejects model writes, cross-team rows and misaligned buckets, and cascades with the monitor', function (): void {
    rollupAdvance($this->monitor);

    $row = rollupHour($this->monitor, '2026-10-02T10:00:00Z');
    $foreignTeam = Team::factory()->business()->create();

    expect(fn () => app(TenantContext::class)->run($this->team->id, fn () => $row->save()))->toThrow(LogicException::class);

    $insert = fn (array $overrides) => DB::transaction(fn () => DB::table('check_rollups_hourly')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'hour' => '2026-10-02T01:00:00Z',
        'checks' => 0,
        'failures' => 0,
        'unknown_checks' => 0,
        'up_us' => 0,
        'down_us' => 0,
        'unknown_us' => 0,
        'excluded_us' => 0,
        'latency_samples' => 0,
        'latency_sum_ms' => 0,
        'latency_counts' => '{}',
        ...$overrides,
    ]));

    expect(fn () => $insert(['team_id' => $foreignTeam->id]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['hour' => '2026-10-02T01:30:00Z']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['up_us' => 3_600_000_001]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['latency_samples' => 1]))->toThrow(QueryException::class);

    app(TenantContext::class)->run($this->team->id, fn () => $this->monitor->delete());

    expect(DB::table('check_rollups_hourly')->count())->toBe(0)
        ->and(DB::table('check_rollups_daily')->count())->toBe(0)
        ->and(DB::table('check_rollup_cursors')->count())->toBe(0);
});

it('continues with later monitors when one fails, and resumes the failed one on the next pass', function (): void {
    $later = rollupMonitor($this->team, '2026-10-02T09:00:00Z');

    DB::unprepared(sprintf(<<<'SQL'
        CREATE FUNCTION reject_rollup_probe() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            RAISE EXCEPTION 'Synthetic rollup failure';
        END;
        $$;
        CREATE TRIGGER reject_rollup_probe BEFORE INSERT ON check_rollups_hourly
            FOR EACH ROW WHEN (NEW.monitor_id = %d) EXECUTE FUNCTION reject_rollup_probe();
        SQL, $this->monitor->id));

    $pass = app(BuildCheckRollups::class)->runPass();

    expect($pass->failed)->toBe(1)
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->exists())->toBeFalse()
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $later->id)->exists())->toBeTrue();

    DB::unprepared('DROP TRIGGER reject_rollup_probe ON check_rollups_hourly; DROP FUNCTION reject_rollup_probe();');

    expect(app(BuildCheckRollups::class)->runPass()->failed)->toBe(0)
        ->and(DB::table('check_rollups_hourly')->where('monitor_id', $this->monitor->id)->exists())->toBeTrue();
});

it('discards a calculation when another run advanced the cursor meanwhile', function (): void {
    rollupAdvance($this->monitor);

    $this->travelTo(CarbonImmutable::parse('2026-10-03T13:30:00Z'));

    $raced = false;

    // The competing run commits while this one is reading its inputs, before it takes any lock.
    DB::listen(function ($query) use (&$raced): void {
        if (!$raced && str_contains($query->sql, 'FROM check_executions execution')) {
            $raced = true;

            DB::table('check_rollup_cursors')
                ->where('monitor_id', $this->monitor->id)
                ->update(['hourly_through' => '2026-10-03T13:00:00Z']);
        }
    });

    expect(rollupAdvance($this->monitor))->toBe(0)
        ->and($raced)->toBeTrue()
        ->and(DB::table('check_rollups_hourly')->where('hour', '2026-10-03T11:00:00Z')->exists())->toBeFalse();
});

it('serializes writers on the cursor row while leaving the monitor row free for the check engine', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);

    try {
        $monitorId = (int) $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->value('id');

        $fixture->connection->table('monitors')->where('id', $monitorId)->update(['created_at' => '2026-10-02T09:00:00Z']);
        $fixture->connection->table('check_rollup_cursors')->insert(['team_id' => $fixture->teamId, 'monitor_id' => $monitorId]);
        $fixture->connection->beginTransaction();
        $fixture->connection->table('check_rollup_cursors')->where('monitor_id', $monitorId)->lockForUpdate()->value('monitor_id');

        DB::statement("SET LOCAL lock_timeout = '100ms'");

        try {
            app(BuildCheckRollups::class)->advance($fixture->teamId, $monitorId);
            $this->fail('A competing run must wait for the cursor held by the other session.');
        } catch (QueryException $exception) {
            // PostgreSQL lock_not_available proves contention at the shared cursor.
            expect($exception->errorInfo[0])->toBe('55P03');
        }

        // The engine's own lock is not blocked by the session holding the cursor. The savepoint releases it again,
        // because the committed fixture cleanup below would otherwise wait for this test's outer transaction.
        DB::beginTransaction();
        expect(DB::table('monitors')->where('id', $monitorId)->lockForUpdate()->value('id'))->toBe($monitorId);
        DB::rollBack();
    } finally {
        $fixture->connection->rollBack();
        $fixture->cleanup();
    }
});

it('runs from the command line and validates rebuild options', function (): void {
    expect(Artisan::call('metrics:rollups'))->toBe(0)
        ->and(Artisan::output())->toContain('Hours written: 26')
        ->and(Artisan::call('metrics:rollups', ['--monitor' => $this->monitor->id]))->toBe(1)
        ->and(Artisan::output())->toContain('together')
        ->and(Artisan::call('metrics:rollups', ['--monitor' => 0, '--from' => '2026-10-02', '--to' => '2026-10-03']))->toBe(1)
        ->and(Artisan::output())->toContain('Unknown monitor')
        ->and(Artisan::call('metrics:rollups', ['--monitor' => $this->monitor->id, '--from' => 'not-a-date', '--to' => '2026-10-03']))->toBe(1)
        ->and(Artisan::output())->toContain('valid instants');

    app(ResultPartitions::class)->ensure(CarbonImmutable::parse('2026-09-15T00:00:00Z'));

    expect(Artisan::call('metrics:rollups', ['--monitor' => $this->monitor->id, '--from' => '2026-10-02T09:00:00Z', '--to' => '2026-10-02T11:00:00Z']))->toBe(0)
        ->and(Artisan::output())->toContain('Hours rewritten: 2');
});

it('does not expose rollups through any route added here', function (): void {
    expect(collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->filter(fn (string $uri) => str_contains($uri, 'rollup'))->all())->toBe([]);
});
