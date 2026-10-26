<?php

declare(strict_types=1);

/** Exercise persistent tenant workers, independent regional queues and shared Redis monitor locks with real processes. */

use App\Actions\Monitoring\ManageMonitors;
use App\Jobs\DeliverCheckBatch;
use App\Models\CheckExecution;
use App\Services\Monitoring\Scheduling\PublishMonitorChecks;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\EngineWorkerProbe;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL, Redis and loopback sockets.');
    }

    CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
    config(['monitor-scheduling.enabled' => true]);
});
afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param CommittedTeamFixture $fixture Committed owner visible to worker subprocesses.
 * @param int $monitorId Existing monitor to configure through the production mutation action.
 * @param string $url Synthetic target, used only by controlled fake or loopback modes.
 * @param list<string> $regions Regional observations reserved at one slot.
 * @param int $intervalSeconds Valid plan cadence, shortened only for the explicit overlap case.
 * @return list<CheckExecution> Committed pending regional executions for that monitor.
 */
function prepareWorkerSlots(
    CommittedTeamFixture $fixture,
    int $monitorId,
    string $url,
    array $regions = ['eu'],
    int $intervalSeconds = 300,
): array {
    return $fixture->committed(function () use ($fixture, $monitorId, $url, $regions, $intervalSeconds): array {
        app(ManageMonitors::class)->update($fixture->owner, $fixture->teamId, $monitorId, [
            'url' => $url,
            'method' => 'get',
            'headers' => [],
            'body' => null,
            'regions' => $regions,
            'interval_seconds' => $intervalSeconds,
            'timeout_ms' => 10000,
        ]);
        DB::table('monitors')->where('id', $monitorId)->update(['next_check_at' => CarbonImmutable::now()->subSecond()]);
        app(ReserveMonitorChecks::class)->run();

        return app(TenantContext::class)->run($fixture->teamId, fn (): array => CheckExecution::query()
            ->where('monitor_id', $monitorId)->orderBy('id')->get()->all());
    });
}

/**
 * @param Process $worker Bounded test worker, which may intentionally report a handled job exception.
 * @return list<array{event: string, uuid: string, context_clean: bool, pid: int, target_attempts: int}> Terminal delivery observations.
 */
function finishEngineWorker(Process $worker): array
{
    $status = $worker->wait();
    expect($status, $worker->getErrorOutput())->toBe(0);
    $events = [];

    foreach (explode("\n", trim($worker->getOutput())) as $line) {
        if (str_starts_with($line, '{')) {
            $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    return $events;
}

/**
 * @param string $hits File collecting exact synthetic target attempts.
 * @param string $release Explicit gate path created only after assertions against the blocked worker.
 * @return array{Process, string} Running bounded server and its authorized synthetic origin.
 */
function engineGateServer(string $hits, string $release): array
{
    $server = new Process([PHP_BINARY, base_path('tests/Fixtures/CheckLoopbackServer.php'), $hits, $release], timeout: 15);
    $server->start();

    try {
        if (!$server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY '))
            || preg_match('/READY 127\.0\.0\.1:(\d+)/', $server->getOutput(), $matches) !== 1) {
            throw new RuntimeException('Synthetic gate server did not become ready.');
        }

        return [$server, 'http://pinned.checker.test:'.$matches[1]];
    } catch (Throwable $exception) {
        $server->stop();

        throw $exception;
    }
}

it('uses one persistent worker across tenants and an exception without leaking context or private outcomes', function (): void {
    $alpha = CommittedTeamFixture::freeTeam(2);
    $beta = CommittedTeamFixture::freeTeam(1);
    $name = 'checks:tenant-probe-'.Str::uuid();
    $queue = Queue::connection('redis');
    $worker = null;

    try {
        $ids = $alpha->connection->table('monitors')->where('team_id', $alpha->teamId)->orderBy('id')->pluck('id');
        $betaId = (int) $beta->connection->table('monitors')->where('team_id', $beta->teamId)->value('id');
        $first = prepareWorkerSlots($alpha, (int) $ids[0], 'https://example.invalid/team/'.$alpha->teamId.'/healthy')[0];
        $failed = prepareWorkerSlots($beta, $betaId, 'https://example.invalid/team/'.$beta->teamId.'/infrastructure')[0];
        $last = prepareWorkerSlots($alpha, (int) $ids[1], 'https://example.invalid/team/'.$alpha->teamId.'/healthy')[0];

        foreach ([$first, $failed] as $work) {
            $queue->push(new DeliverCheckBatch($work->team_id, [$work->id => $work->monitor_id], 'eu'), '', $name);
        }

        // A foreign tenant envelope must be harmless even between an exception and the next valid job.
        $queue->push(new DeliverCheckBatch($beta->teamId, [$last->id => $last->monitor_id], 'eu'), '', $name);
        $queue->push(new DeliverCheckBatch($last->team_id, [$last->id => $last->monitor_id], 'eu'), '', $name);
        $worker = EngineWorkerProbe::make($name, 'pipeline', 4);
        $worker->start();
        $events = finishEngineWorker($worker);
        expect($events)->toHaveCount(4)
            ->and(array_unique(array_column($events, 'pid')))->toHaveCount(1)
            ->and(array_column($events, 'context_clean'))->toBe([true, true, true, true])
            ->and(array_column($events, 'target_attempts'))->toBe([1, 2, 2, 3])
            ->and(array_column($events, 'event'))->toBe(['JobProcessed', 'JobExceptionOccurred', 'JobProcessed', 'JobProcessed']);

        foreach ([$first, $failed, $last] as $work) {
            $row = $alpha->connection->table('check_results')->where('execution_id', $work->id)->sole();
            expect((int) $row->team_id)->toBe($work->team_id)
                ->and($row->status)->toBe($work->id === $failed->id ? 'unknown' : 'up')
                ->and($row->kind)->toBe($work->id === $failed->id ? 'infrastructure_failed' : 'observed')
                ->and($row->error_message ?? '')->not->toContain('private worker');
            $summary = $alpha->connection->table('monitors')->where('id', $work->monitor_id)->first();
            expect($summary->last_status)->toBe($row->status)
                ->and($summary->last_checked_at === null)->toBe($work->id === $failed->id);
            $owner = $alpha->connection->table('check_executions')->where('id', $work->id)->first();
            expect($owner->queue_uuid)->toBeIn(array_column($events, 'uuid'))
                ->and((int) $owner->queue_attempt)->toBe(1);
        }

        $alpha->committed(function () use ($alpha, $beta): void {
            app(TenantContext::class)->run($alpha->teamId, function () use ($beta): void {
                expect(App\Models\CheckResult::query()->count())->toBe(2)
                    ->and(App\Models\CheckResult::query()->where('team_id', $beta->teamId)->count())->toBe(0);
            });
        });
        expect($queue->size($name))->toBe(0);
    } finally {
        $worker?->stop();
        $queue->clear($name);
        $alpha->cleanup();
        $beta->cleanup();
    }
});

it('completes the US region while EU is waiting for a gated target and then reconciles agreement', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $hits = tempnam(sys_get_temp_dir(), 'engine-regions-');
    $release = $hits.'.release';
    $server = null;
    $workers = [];
    $queue = Queue::connection('redis');
    $prefix = 'checks:regions-'.Str::uuid();

    try {
        [$server, $origin] = engineGateServer($hits, $release);
        $monitorId = (int) $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->value('id');
        $work = prepareWorkerSlots($fixture, $monitorId, $origin.'/gate-first', ['eu', 'us']);
        $eu = $work[0];
        $us = $work[1];
        $queue->push(new DeliverCheckBatch($fixture->teamId, [$eu->id => $monitorId], 'eu'), '', $prefix.'-eu');
        $workers[] = $blocked = EngineWorkerProbe::make($prefix.'-eu', 'loopback', origin: $origin);
        $blocked->start();
        expect($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'HIT /gate')))->toBeTrue();

        $queue->push(new DeliverCheckBatch($fixture->teamId, [$us->id => $monitorId], 'us'), '', $prefix.'-us');
        $workers[] = $healthy = EngineWorkerProbe::make($prefix.'-us', 'loopback', origin: $origin);
        $healthy->start();
        expect(finishEngineWorker($healthy))->toHaveCount(1)
            ->and($blocked->isRunning())->toBeTrue()
            ->and($fixture->connection->table('check_executions')->where('id', $eu->id)->value('state'))->toBe('running')
            ->and($fixture->connection->table('check_results')->where('execution_id', $us->id)->value('status'))->toBe('up')
            ->and($fixture->connection->table('monitors')->where('id', $monitorId)->value('last_status'))->toBe('unknown');
        touch($release);
        expect(finishEngineWorker($blocked))->toHaveCount(1)
            ->and($fixture->connection->table('monitors')->where('id', $monitorId)->value('last_status'))->toBe('up')
            ->and(file($hits, FILE_IGNORE_NEW_LINES))->toBe(['/gate-first', '/gate-first']);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }

        $server?->stop();
        $queue->clear($prefix.'-eu');
        $queue->clear($prefix.'-us');
        $fixture->cleanup();
        @unlink($release);
        unlink($hits);
    }
});

it('skips a later slot under another process Redis lock and recovers it through outbox publication', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $hits = tempnam(sys_get_temp_dir(), 'engine-overlap-');
    $release = $hits.'.release';
    $server = null;
    $workers = [];
    $queue = Queue::connection('redis');
    $prefix = 'checks:overlap-'.Str::uuid();

    try {
        [$server, $origin] = engineGateServer($hits, $release);
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->update(['plan' => 'business']);
        $monitorId = (int) $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->value('id');
        $first = prepareWorkerSlots($fixture, $monitorId, $origin.'/gate', intervalSeconds: 30)[0];
        $queue->push(new DeliverCheckBatch($fixture->teamId, [$first->id => $monitorId], 'eu'), '', $prefix.'-first');
        $workers[] = $blocked = EngineWorkerProbe::make($prefix.'-first', 'loopback', origin: $origin);
        $blocked->start();
        expect($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'HIT /gate')))->toBeTrue();
        CarbonImmutable::setTestNow($first->slot_at->addSeconds(30));
        $second = $fixture->committed(function () use ($fixture, $monitorId): CheckExecution {
            app(ReserveMonitorChecks::class)->run();

            return app(TenantContext::class)->run($fixture->teamId, fn (): CheckExecution => CheckExecution::query()
                ->where('monitor_id', $monitorId)->orderByDesc('slot_at')->firstOrFail());
        });
        expect($second->id)->not->toBe($first->id);
        $queue->push(new DeliverCheckBatch($fixture->teamId, [$second->id => $monitorId], 'eu'), '', $prefix.'-second');
        $workers[] = $overlap = EngineWorkerProbe::make($prefix.'-second', 'loopback', origin: $origin);
        $overlap->start();
        $skipped = finishEngineWorker($overlap);
        expect($skipped)->toHaveCount(1)
            ->and($skipped[0]['event'])->toBe('JobProcessed')
            ->and($fixture->connection->table('check_executions')->where('id', $second->id)->value('state'))->toBe('pending')
            ->and($fixture->connection->table('check_executions')->where('id', $second->id)->value('queue_uuid'))->toBeNull()
            ->and(file($hits, FILE_IGNORE_NEW_LINES))->toBe(['/gate']);
        touch($release);
        finishEngineWorker($blocked);
        $fixture->committed(fn (): int => app(PublishMonitorChecks::class)->run());
        $workers[] = $redelivery = EngineWorkerProbe::make('checks:eu', 'loopback', origin: $origin);
        $redelivery->start();
        expect(finishEngineWorker($redelivery))->toHaveCount(1)
            ->and($fixture->connection->table('check_results')->where('monitor_id', $monitorId)->count())->toBe(2)
            ->and($fixture->connection->table('check_results')->where('execution_id', $second->id)->value('status'))->toBe('up')
            ->and(file($hits, FILE_IGNORE_NEW_LINES))->toBe(['/gate', '/gate']);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }

        $server?->stop();
        $queue->clear($prefix.'-first');
        $queue->clear($prefix.'-second');
        $queue->clear('checks:eu');
        $fixture->cleanup();
        @unlink($release);
        unlink($hits);
    }
});
