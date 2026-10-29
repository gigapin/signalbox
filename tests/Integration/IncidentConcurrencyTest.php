<?php

declare(strict_types=1);

/** Race regional confirmations and manual changes in separate PostgreSQL sessions without duplicating incidents. */

use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Models\CheckExecution;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
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
 * @param string $mode `down`, `up` or `resolve`.
 * @param int $teamId Committed owner.
 * @param int $targetId Execution for observations, incident for a manual resolution.
 * @param int $actorId Manager resolving manually; unused by observations.
 * @return Process Bounded child with only isolated service configuration and the parent's fixed clock.
 */
function incidentProbe(string $mode, int $teamId, int $targetId, int $actorId = 0): Process
{
    return new Process([PHP_BINARY, base_path('tests/Fixtures/RunIncidentProbe.php'), $mode, (string) $teamId, (string) $targetId, (string) $actorId], base_path(), [
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
        'INCIDENT_PROBE_NOW' => CarbonImmutable::now('UTC')->toIso8601String(),
    ], timeout: 12);
}

/**
 * @param CommittedTeamFixture $fixture Connection holding the barrier lock.
 * @param list<Process> $processes Race participants whose database waits must be observed.
 * @throws RuntimeException When a participant exits or never reaches the barrier.
 */
function awaitIncidentWaiters(CommittedTeamFixture $fixture, array $processes): void
{
    $until = microtime(true) + 5;

    do {
        $fixture->connection->select('SELECT pg_stat_clear_snapshot()');
        $waiting = (int) $fixture->connection->scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'signalbox-incident-probe' AND wait_event_type = 'Lock'");

        if ($waiting === count($processes)) {
            return;
        }

        foreach ($processes as $process) {
            if (!$process->isRunning()) {
                throw new RuntimeException('Incident participant exited early: '.$process->getOutput().$process->getErrorOutput());
            }
        }

        // Poll a real PostgreSQL barrier instead of assuming a process starts within an arbitrary delay.
        usleep(10_000);
    } while (microtime(true) < $until);

    throw new RuntimeException('Incident participants never reached the PostgreSQL barrier.');
}

/**
 * Commit a two-region monitor whose first slot is due now.
 *
 * @param CommittedTeamFixture $fixture Committed team with one monitor.
 * @return int Monitor identity.
 */
function incidentRaceMonitor(CommittedTeamFixture $fixture): int
{
    $monitorId = (int) $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->value('id');
    $slot = app(MonitorSlots::class)->latest($monitorId, 300, CarbonImmutable::now('UTC'));
    CarbonImmutable::setTestNow($slot);
    $fixture->connection->table('monitors')->where('id', $monitorId)->update([
        'regions_json' => '["eu","us"]',
        'next_check_at' => $slot,
    ]);

    return $monitorId;
}

/**
 * Reserve the monitor's next slot at its scheduled instant through committed production code.
 *
 * @param CommittedTeamFixture $fixture Committed team.
 * @param int $monitorId Monitor whose slot is due.
 * @return array<string, int> Pending execution identity per region value.
 */
function incidentRaceReserve(CommittedTeamFixture $fixture, int $monitorId): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse((string) $fixture->connection->table('monitors')->where('id', $monitorId)->value('next_check_at')));
    $fixture->committed(fn (): int => app(ReserveMonitorChecks::class)->run());

    return $fixture->connection->table('check_executions')
        ->where('monitor_id', $monitorId)
        ->where('state', 'pending')
        ->pluck('id', 'region')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
}

/**
 * Complete both regions of the next slot sequentially in committed transactions.
 *
 * @param CommittedTeamFixture $fixture Committed team.
 * @param int $monitorId Monitor whose slot is due.
 * @param MonitorStatus $status Observation reported by both regions.
 */
function incidentRaceSlot(CommittedTeamFixture $fixture, int $monitorId, MonitorStatus $status): void
{
    foreach (incidentRaceReserve($fixture, $monitorId) as $workId) {
        $fixture->committed(function () use ($fixture, $workId, $status): void {
            $ledger = app(ExecutionLedger::class);
            $acquired = $ledger->acquire($fixture->teamId, $workId);
            expect($acquired)->toBeInstanceOf(CheckExecution::class);

            $down = $status === MonitorStatus::Down;
            $context = new CheckContext($fixture->teamId, $acquired->monitor_id, $acquired->id, MonitorRegion::from($acquired->region), $acquired->slot_at);
            $ledger->finish($acquired, false, new CheckOutcome($context, $status, CarbonImmutable::now('UTC'), 10.0, $down ? 503 : 200, $down ? CheckError::UnexpectedStatus : null));
        });
    }
}

/**
 * Hold the team row lock while every participant starts, then release them together.
 *
 * @param CommittedTeamFixture $fixture Connection holding the barrier.
 * @param list<array{string, int, int}> $participants Mode, target and actor of each child process.
 * @return list<mixed> Each participant's JSON result, in start order.
 */
function incidentRace(CommittedTeamFixture $fixture, array $participants): array
{
    $processes = [];

    try {
        $fixture->connection->beginTransaction();

        try {
            $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();

            foreach ($participants as [$mode, $targetId, $actorId]) {
                $process = incidentProbe($mode, $fixture->teamId, $targetId, $actorId);
                $process->start();
                $processes[] = $process;
            }

            awaitIncidentWaiters($fixture, $processes);
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

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}

it('opens exactly one incident when both regions confirm the same slot in parallel sessions', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);

    try {
        $monitorId = incidentRaceMonitor($fixture);
        incidentRaceSlot($fixture, $monitorId, MonitorStatus::Down);
        incidentRaceSlot($fixture, $monitorId, MonitorStatus::Down);
        $works = incidentRaceReserve($fixture, $monitorId);
        expect($fixture->connection->table('incidents')->where('monitor_id', $monitorId)->count())->toBe(0);

        $results = incidentRace($fixture, [['down', $works['eu'], 0], ['down', $works['us'], 0]]);

        $incidents = $fixture->connection->table('incidents')->where('monitor_id', $monitorId)->get();
        expect($results)->toBe([true, true])
            ->and($incidents)->toHaveCount(1)
            ->and($incidents[0]->resolved_at)->toBeNull()
            ->and($fixture->connection->table('incident_updates')->where('incident_id', $incidents[0]->id)->pluck('message')->all())
            ->toBe(['Failure confirmed after 3 consecutive failed checks in every configured region (unexpected_status).'])
            ->and((int) $fixture->connection->table('monitors')->where('id', $monitorId)->value('consecutive_failures'))->toBe(3)
            ->and($fixture->connection->table('check_results')->where('monitor_id', $monitorId)->count())->toBe(6);
    } finally {
        $fixture->cleanup();
    }
});

it('settles a recovery racing a manual resolution into one resolved incident', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);

    try {
        $monitorId = incidentRaceMonitor($fixture);

        foreach (range(1, 3) as $ignored) {
            incidentRaceSlot($fixture, $monitorId, MonitorStatus::Down);
        }

        $incidentId = (int) $fixture->connection->table('incidents')->where('monitor_id', $monitorId)->value('id');
        $works = incidentRaceReserve($fixture, $monitorId);

        $results = incidentRace($fixture, [
            ['up', $works['eu'], 0],
            ['resolve', $incidentId, $fixture->owner->id],
            ['up', $works['us'], 0],
        ]);

        $incidents = $fixture->connection->table('incidents')->where('monitor_id', $monitorId)->get();
        $messages = $fixture->connection->table('incident_updates')->where('incident_id', $incidentId)->orderBy('id')->pluck('message')->all();
        $resolvedEntries = $fixture->connection->table('incident_updates')->where('incident_id', $incidentId)->where('status', 'resolved')->count();

        expect($results[0])->toBeTrue()
            ->and($results[2])->toBeTrue()
            ->and($incidents)->toHaveCount(1)
            ->and($incidents[0]->status)->toBe('resolved')
            ->and($incidents[0]->resolved_at)->not->toBeNull()
            ->and((int) $incidents[0]->reopened_count)->toBe(0)
            ->and(array_count_values($messages)['Resolved manually during the race.'])->toBe(1)
            // Manual first: the recovery finds nothing open. Recovery first: the manual entry becomes a note.
            ->and($resolvedEntries)->toBeIn([1, 2])
            ->and(count($messages))->toBe($resolvedEntries + 1);
    } finally {
        $fixture->cleanup();
    }
});
