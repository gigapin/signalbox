<?php

declare(strict_types=1);

/** Prove reservation, publication and acquisition exclusion with concurrent PostgreSQL sessions. */

use App\Services\Monitoring\Scheduling\MonitorSlots;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\TestDatabaseGuard;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    config(['monitor-scheduling.enabled' => true]);
});
afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param string $mode Production operation to race.
 * @param int $teamId Committed owner.
 * @param int $executionId Committed outbox identity, unused by dispatch.
 * @return Process Bounded child with only isolated service configuration and a fixed clock.
 */
function schedulingProbe(string $mode, int $teamId, int $executionId): Process
{
    return new Process([PHP_BINARY, base_path('tests/Fixtures/RunSchedulingProbe.php'), $mode, (string) $teamId, (string) $executionId], base_path(), [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_DATABASE' => TestDatabaseGuard::DATABASE,
        'DB_USERNAME' => TestDatabaseGuard::USERNAME,
        'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        'DB_URL' => '',
        'REDIS_HOST' => (string) config('database.redis.default.host'),
        'REDIS_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'null',
        'SCHEDULING_PROBE_NOW' => CarbonImmutable::now()->toIso8601String(),
    ], timeout: 12);
}

/**
 * @param CommittedTeamFixture $fixture Connection holding the barrier lock.
 * @param list<Process> $processes Race participants whose database waits must be observed.
 * @throws RuntimeException When a participant exits or never reaches the expected lock.
 */
function awaitSchedulingWaiters(CommittedTeamFixture $fixture, array $processes): void
{
    $until = microtime(true) + 5;

    do {
        $fixture->connection->select('SELECT pg_stat_clear_snapshot()');
        $waiting = (int) $fixture->connection->scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'signalbox-scheduling-probe' AND wait_event_type = 'Lock'");

        if ($waiting === count($processes)) {
            return;
        }

        foreach ($processes as $process) {
            if (!$process->isRunning()) {
                throw new RuntimeException('Scheduling participant exited early: '.$process->getOutput().$process->getErrorOutput());
            }
        }

        // Poll a real PostgreSQL barrier instead of assuming a process starts within an arbitrary delay.
        usleep(10_000);
    } while (microtime(true) < $until);

    throw new RuntimeException('Scheduling participants never reached the PostgreSQL barrier: '.json_encode($fixture->connection->select('SELECT application_name, wait_event_type, wait_event FROM pg_stat_activity WHERE datname = current_database()')));
}

it('serializes concurrent scheduling participants before their domain effect', function (string $mode): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $processes = [];

    try {
        $monitorId = (int) $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->value('id');
        $slot = app(MonitorSlots::class)->latest($monitorId, 300, CarbonImmutable::now());
        CarbonImmutable::setTestNow($slot->addSecond());
        $fixture->connection->table('monitors')->where('id', $monitorId)->update(['next_check_at' => $slot]);
        $workId = 0;

        if ($mode === 'downgrade') {
            $fixture->connection->table('teams')->where('id', $fixture->teamId)->update(['plan' => 'business']);
            $fixture->connection->table('monitors')->where('id', $monitorId)->update(['interval_seconds' => 30]);
        }

        if (!in_array($mode, ['dispatch', 'downgrade'], true)) {
            $fixture->committed(fn () => app(ReserveMonitorChecks::class)->run());
            $workId = (int) $fixture->connection->table('check_executions')->where('monitor_id', $monitorId)->value('id');
        }

        $fixture->connection->beginTransaction();

        try {
            if ($mode === 'publish') {
                $fixture->connection->table('check_executions')->where('id', $workId)->lockForUpdate()->first();
            } else {
                $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
            }

            if ($mode === 'downgrade') {
                $fixture->connection->table('teams')->where('id', $fixture->teamId)->update(['plan' => 'free']);
            }

            foreach (range(1, 2) as $ignored) {
                $process = schedulingProbe($mode === 'downgrade' ? 'dispatch' : $mode, $fixture->teamId, $workId);
                $process->start();
                $processes[] = $process;
            }

            awaitSchedulingWaiters($fixture, $processes);
            $fixture->connection->commit();
        } catch (Throwable $exception) {
            $fixture->connection->rollBack();

            throw $exception;
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['result'];
        }

        expect($fixture->connection->table('check_executions')->where('monitor_id', $monitorId)->count())->toBe($mode === 'downgrade' ? 0 : 1);

        if ($mode === 'downgrade') {
            expect($results)->toBe([0, 0]);
        } elseif ($mode === 'dispatch') {
            expect($results)->toBe([0, 0]);
            $next = CarbonImmutable::parse($fixture->connection->table('monitors')->where('id', $monitorId)->value('next_check_at'));
            expect($next->equalTo($slot->addSeconds(300)))->toBeTrue();
        } else {
            sort($results);
            expect($results)->toBe([false, true]);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        $fixture->cleanup();
    }
})->with(['dispatch', 'publish', 'acquire', 'downgrade']);

it('keeps uncommitted reservations invisible to another publisher and removes them on rollback', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $process = null;

    try {
        $monitorId = (int) $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->value('id');
        $due = CarbonImmutable::now()->subSecond()->startOfSecond();
        $fixture->connection->table('monitors')->where('id', $monitorId)->update(['next_check_at' => $due]);
        $fixture->connection->beginTransaction();

        try {
            $fixture->committed(fn () => app(ReserveMonitorChecks::class)->run());
            $workId = (int) $fixture->connection->table('check_executions')->where('monitor_id', $monitorId)->value('id');
            expect($workId)->toBeGreaterThan(0);
            $process = schedulingProbe('publish', $fixture->teamId, $workId);
            $process->mustRun();
            expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['result'])->toBeFalse();
        } finally {
            $fixture->connection->rollBack();
        }

        expect($fixture->connection->table('check_executions')->where('monitor_id', $monitorId)->count())->toBe(0)
            ->and(CarbonImmutable::parse($fixture->connection->table('monitors')->where('id', $monitorId)->value('next_check_at'))->equalTo($due))->toBeTrue();
    } finally {
        if ($process?->isRunning()) {
            $process->stop();
        }

        $fixture->cleanup();
    }
});

it('serializes concurrent creation of a missing result partition with a PostgreSQL advisory barrier', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $processes = [];
    try {
        $fixture->connection->beginTransaction();
        $fixture->connection->select("SELECT pg_advisory_xact_lock(1936287589, hashtext('check_results_2031_01'))");
        foreach (range(1, 2) as $ignored) {
            $process = schedulingProbe('partition', $fixture->teamId, 0);
            $process->start();
            $processes[] = $process;
        }
        awaitSchedulingWaiters($fixture, $processes);
        $fixture->connection->commit();
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0)
                ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['result'])->toBe('check_results_2031_01');
        }
        expect((int) $fixture->connection->scalar("SELECT count(*) FROM pg_inherits WHERE inhrelid = 'check_results_2031_01'::regclass AND inhparent = 'check_results'::regclass"))->toBe(1);
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }
        $fixture->connection->statement('DROP TABLE IF EXISTS check_results_2031_01');
        $fixture->cleanup();
    }
});

it('races abandoned-work recovery against the fresh failed hook without duplicating results', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $processes = [];
    try {
        $work = $fixture->committed(function () use ($fixture): App\Models\CheckExecution {
            Illuminate\Support\Facades\DB::table('monitors')->where('team_id', $fixture->teamId)->update(['next_check_at' => CarbonImmutable::now()->subSecond()]);
            app(ReserveMonitorChecks::class)->run();
            $work = app(App\Services\Tenancy\TenantContext::class)->run($fixture->teamId, fn () => App\Models\CheckExecution::query()->sole());
            return app(App\Services\Monitoring\Scheduling\ExecutionLedger::class)->acquire($fixture->teamId, $work->id, '00000000-0000-4000-8000-000000000001', 1);
        });
        CarbonImmutable::setTestNow($work->deadline_at);
        $fixture->connection->beginTransaction();
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
        foreach (['recover', 'fail'] as $mode) {
            $process = schedulingProbe($mode, $fixture->teamId, $work->id);
            $process->start();
            $processes[] = $process;
        }
        awaitSchedulingWaiters($fixture, $processes);
        $fixture->connection->commit();
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
        }
        expect($fixture->connection->table('check_results')->where('execution_id', $work->id)->count())->toBe(1)
            ->and($fixture->connection->table('check_results')->where('execution_id', $work->id)->value('kind'))->toBe('infrastructure_failed');
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }
        $fixture->cleanup();
    }
});
