<?php

declare(strict_types=1);

/** Prove after-commit publication and concurrent event/delivery deduplication in independent PostgreSQL sessions. */

use App\Enums\Monitoring\CheckError;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\Incidents\IncidentLifecycle;
use App\Services\Notifications\PublishNotificationDeliveries;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\RecordingNotificationPublisher;
use Tests\Support\TestDatabaseGuard;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'));
    $this->publisher = RecordingNotificationPublisher::bind();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param CommittedTeamFixture $fixture Independent committing session.
 * @param bool $open Whether to use the real lifecycle or leave only an incident for parallel preparation.
 * @return array{incident: int, monitor: int} Committed identities; public events publish only after commit.
 */
function notificationRaceFixture(CommittedTeamFixture $fixture, bool $open): array
{
    return $fixture->committed(fn (): array => app(TenantContext::class)->run($fixture->teamId, fn (): array => DB::transaction(function () use ($fixture, $open): array {
        Team::query()->whereKey($fixture->teamId)->lockForUpdate()->firstOrFail();
        $monitor = Monitor::query()->lockForUpdate()->firstOrFail();
        $channel = NotificationChannel::factory()->webhook()->active()->create();
        EscalationPolicy::factory()->create(['is_default' => true, 'steps_json' => [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]]);
        $incident = $open
            ? app(IncidentLifecycle::class)->open($monitor, CarbonImmutable::now()->subMinute(), CheckError::UnexpectedStatus, CarbonImmutable::now())
            : Incident::factory()->create(['monitor_id' => $monitor->id]);

        return ['incident' => $incident->id, 'monitor' => $monitor->id];
    })));
}

/**
 * @param string $mode prepare or deliver.
 * @param int $teamId Committed tenant.
 * @param int $targetId Incident identity for preparation, delivery identity for sending.
 * @param string $argument Monitor identity or synthetic provider receipt path.
 * @return Process Guarded, bounded child using the production bootstrap and fake provider.
 */
function notificationProbe(string $mode, int $teamId, int $targetId, string $argument): Process
{
    return new Process([PHP_BINARY, base_path('tests/Fixtures/RunNotificationProbe.php'), $mode, (string) $teamId, (string) $targetId, $argument], base_path(), [
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
        'NOTIFICATION_PROBE_NOW' => CarbonImmutable::now()->toIso8601String(),
    ], timeout: 15);
}

/**
 * @param CommittedTeamFixture $fixture Session holding the team barrier.
 * @param list<Process> $children Participants that must be observed waiting for that database lock.
 * @throws RuntimeException When a child exits or never reaches the barrier.
 */
function awaitNotificationParticipants(CommittedTeamFixture $fixture, array $children): void
{
    $deadline = microtime(true) + 5;

    do {
        $fixture->connection->select('SELECT pg_stat_clear_snapshot()');
        $waiting = (int) $fixture->connection->scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'signalbox-notification-probe' AND wait_event_type = 'Lock'");

        if ($waiting === count($children)) {
            return;
        }

        foreach ($children as $child) {
            if (!$child->isRunning()) {
                throw new RuntimeException('Notification probe exited early: '.$child->getErrorOutput());
            }
        }

        usleep(10000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Notification probes did not reach the database barrier.');
}

it('publishes only committed intents and recovers a failed after-commit broker submission', function (bool $brokerFails): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $testTransactions = app('db.transactions');
    $transactions = new DatabaseTransactionsManager();
    // RefreshDatabase treats the outer test transaction as committed; this independent connection needs production semantics.
    app()->instance('db.transactions', $transactions);
    $fixture->connection->setTransactionManager($transactions);

    try {
        $this->publisher->failTeams = $brokerFails ? [$fixture->teamId] : [];
        $publicationsBeforeCommit = null;
        $fixture->connection->beginTransaction();
        $identities = notificationRaceFixture($fixture, true);
        $publicationsBeforeCommit = count($this->publisher->sent);
        expect($fixture->connection->table('notification_logs')->where('incident_id', $identities['incident'])->count())->toBe(1)
            ->and($publicationsBeforeCommit)->toBe(0);
        $fixture->connection->commit();
        expect($this->publisher->sent)->toHaveCount($brokerFails ? 0 : 1);

        $this->publisher->failTeams = [];
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
        $pass = app(PublishNotificationDeliveries::class)->run();
        expect($pass['published'])->toBe(1)
            ->and($fixture->connection->table('notification_logs')->where('incident_id', $identities['incident'])->value('attempts'))->toBe(0);
    } finally {
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }

        app()->instance('db.transactions', $testTransactions);
        $fixture->cleanup();
    }
})->with([false, true]);

it('prepares one frozen audience when duplicate events race in separate sessions', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $children = [];

    try {
        $ids = notificationRaceFixture($fixture, false);
        $fixture->connection->beginTransaction();
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();

        foreach (range(1, 2) as $participant) {
            $child = notificationProbe('prepare', $fixture->teamId, $ids['incident'], (string) $ids['monitor']);
            $child->start();
            $children[] = $child;
        }

        awaitNotificationParticipants($fixture, $children);
        $fixture->connection->commit();

        foreach ($children as $child) {
            $child->wait();
            expect($child->isSuccessful())->toBeTrue($child->getErrorOutput());
        }

        expect($fixture->connection->table('notification_logs')->where('incident_id', $ids['incident'])->count())->toBe(1)
            ->and($fixture->connection->table('incident_notification_events')->where('incident_id', $ids['incident'])->count())->toBe(1);
    } finally {
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }

        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->stop();
            }
        }

        $fixture->cleanup();
    }
});

it('makes one provider call when duplicate deliveries race while the accepted provider request is still in flight', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $children = [];
    $receipts = sys_get_temp_dir().'/signalbox-notification-receipts-'.bin2hex(random_bytes(8));
    $deliveryId = null;

    try {
        $ids = notificationRaceFixture($fixture, true);
        $deliveryId = (int) $fixture->connection->table('notification_logs')->where('incident_id', $ids['incident'])->value('id');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
        $fixture->connection->select('SELECT pg_advisory_lock(177503, ?)', [$deliveryId]);
        $fixture->connection->beginTransaction();
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();

        foreach (range(1, 2) as $participant) {
            $child = notificationProbe('deliver', $fixture->teamId, $deliveryId, $receipts);
            $child->start();
            $children[] = $child;
        }

        awaitNotificationParticipants($fixture, $children);
        $fixture->connection->commit();
        $deadline = microtime(true) + 5;

        do {
            $accepted = is_file($receipts) ? file($receipts, FILE_IGNORE_NEW_LINES) : [];
            $completed = count(array_filter($children, fn (Process $child): bool => !$child->isRunning()));

            if (count($accepted) === 1 && $completed === 1) {
                break;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        expect($accepted)->toBe(['accepted'])->and($completed)->toBe(1);
        $fixture->connection->select('SELECT pg_advisory_unlock(177503, ?)', [$deliveryId]);

        foreach ($children as $child) {
            $child->wait();
            expect($child->isSuccessful())->toBeTrue($child->getErrorOutput());
        }

        $row = $fixture->connection->table('notification_logs')->where('id', $deliveryId)->first();
        expect($row->status)->toBe('sent')->and($row->attempts)->toBe(1)
            ->and(file($receipts, FILE_IGNORE_NEW_LINES))->toBe(['accepted']);
    } finally {
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }

        if ($deliveryId !== null) {
            $fixture->connection->select('SELECT pg_advisory_unlock(177503, ?)', [$deliveryId]);
        }

        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->stop();
            }
        }

        if (is_file($receipts)) {
            unlink($receipts);
        }

        $fixture->cleanup();
    }
});
