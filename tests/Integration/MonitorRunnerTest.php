<?php

declare(strict_types=1);

/** Exercise production runner admission and partitioned atomic results on isolated PostgreSQL and Redis. */

use App\Contracts\Monitoring\DnsTransport;
use App\Contracts\Monitoring\TlsTransport;
use App\Contracts\Security\HostResolver;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\MonitorRegions;
use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Exceptions\MissingTenantContext;
use App\Jobs\DeliverCheckBatch;
use App\Models\CheckExecution;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\Checks\CheckRuntimeLimits;
use App\Services\Monitoring\Checks\RunMonitorChecks;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use Symfony\Component\Process\Process;
use Tests\Support\FakeDnsTransport;
use Tests\Support\FakeHostResolver;
use Tests\Support\FakeTlsTransport;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and Redis.');
    }
    CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
    config(['monitor-scheduling.enabled' => true]);
    app()->instance(HostResolver::class, new FakeHostResolver(['example.invalid' => ['93.184.216.34']]));
    Http::fake(['*' => Http::response('healthy', 200)]);
});
afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Explicit owner.
 * @param string $type Factory variant.
 * @param array<string, mixed> $attributes Scheduling attributes.
 * @return Monitor Due monitor.
 */
function runnerMonitor(Team $team, string $type = 'http', array $attributes = []): Monitor
{
    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->{$type}()->create([
        'next_check_at' => CarbonImmutable::now()->subSecond(), ...$attributes,
    ]));
    if ($type === 'heartbeat') {
        DB::table('monitor_heartbeats')->where('monitor_id', $monitor->id)->update(['started_at' => CarbonImmutable::now()->subHour()]);
    }
    return $monitor;
}

/**
 * @param Team $team Explicit owner.
 * @return Illuminate\Database\Eloquent\Collection<int, CheckExecution> Reserved regional slots.
 */
function runnerWork(Team $team): Illuminate\Database\Eloquent\Collection
{
    app(ReserveMonitorChecks::class)->run();
    return app(TenantContext::class)->run($team->id, fn () => CheckExecution::query()->orderBy('id')->get());
}

/**
 * @param CheckExecution $work Admitted slot.
 * @param MonitorStatus $status Synthetic observation.
 * @return CheckOutcome Safe correlated outcome.
 */
function runnerOutcome(CheckExecution $work, MonitorStatus $status = MonitorStatus::Up): CheckOutcome
{
    return new CheckOutcome(new CheckContext($work->team_id, $work->monitor_id, $work->id, MonitorRegion::from($work->region), $work->slot_at), $status, CarbonImmutable::now('UTC'), 12.6, 200, $status === MonitorStatus::Down ? App\Enums\Monitoring\CheckError::UnexpectedStatus : null);
}

it('runs every checker variant and records genuine observations exactly once', function (string $type): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team, $type);
    $dns = new FakeDnsTransport();
    $dns->answers['example.invalid.|1'] = Message::createResponseWithAnswersForQuery(new Query('example.invalid.', 1, 1), [new Record('example.invalid', 1, 1, 60, $type === 'tls' ? '93.184.216.34' : '192.0.2.1')]);
    app()->instance(DnsTransport::class, $dns);
    app()->instance(TlsTransport::class, new FakeTlsTransport());
    $work = runnerWork($team)->sole();
    $runner = app(RunMonitorChecks::class);
    $runner->consumeBatch($team->id, [$work->id => $monitor->id], 'eu', null, null);
    $runner->consumeBatch($team->id, [$work->id => $monitor->id], 'eu', null, null);
    app(TenantContext::class)->run($team->id, function () use ($work, $type, $monitor): void {
        $result = CheckResult::query()->sole();
        expect($result->kind)->toBe(CheckResultKind::Observed)
            ->and($result->status)->toBe($type === 'heartbeat' ? MonitorStatus::Down : MonitorStatus::Up)
            ->and($result->checked_at)->not->toBeNull()
            ->and($result->execution_id)->toBe($work->id)
            ->and($monitor->fresh()->last_status)->toBe($result->status)
            ->and($monitor->fresh()->last_result_slot_at->equalTo($work->slot_at))->toBeTrue()
            ->and(CheckExecution::query()->findOrFail($work->id)->state)->toBe('completed');
        if ($type === 'tls') {
            expect($monitor->fresh()->ssl_expires_at)->not->toBeNull();
        }
    });
    if (in_array($type, ['http', 'keyword'], true)) {
        Http::assertSentCount(1);
    }
})->with(['http', 'keyword', 'tls', 'dns', 'heartbeat']);

it('rejects foreign identities and wrong monitor or region before acquisition and restores scope', function (): void {
    $team = Team::factory()->business()->create();
    $other = Team::factory()->business()->create();
    $monitor = runnerMonitor($team);
    $work = runnerWork($team)->sole();
    foreach ([[$other->id, $monitor->id, 'eu'], [$team->id, $monitor->id + 1, 'eu'], [$team->id, $monitor->id, 'us']] as [$owner, $id, $region]) {
        app(RunMonitorChecks::class)->consumeBatch($owner, [$work->id => $id], $region, null, null);
    }
    expect(DB::table('check_executions')->value('state'))->toBe('pending');
    Http::assertNothingSent();
    expect(fn () => CheckResult::query()->count())->toThrow(MissingTenantContext::class);
});

it('records non-observation outcomes without inventing target health', function (string $path): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team);
    $work = runnerWork($team)->sole();
    $ledger = app(ExecutionLedger::class);
    if ($path === 'cancelled') {
        DB::table('monitors')->where('id', $monitor->id)->update(['is_paused' => true]);
        $ledger->acquire($team->id, $work->id);
    } elseif ($path === 'missed') {
        CarbonImmutable::setTestNow($work->expires_at);
        $ledger->recover($team->id, $work->id);
    } else {
        $acquired = $ledger->acquire($team->id, $work->id);
        CarbonImmutable::setTestNow($acquired->deadline_at);
        expect($ledger->recover($team->id, $work->id))->toBeTrue();
        expect($ledger->finish($acquired, false, runnerOutcome($acquired)))->toBeFalse();
    }
    $ledger->recover($team->id, $work->id);
    $result = DB::table('check_results')->sole();
    expect($result->kind)->toBe($path)
        ->and($result->status)->toBe('unknown')
        ->and($result->checked_at)->toBeNull()
        ->and($result->response_time_ms)->toBeNull()
        ->and(DB::table('monitors')->value('last_checked_at'))->toBeNull();
    Http::assertNothingSent();
})->with(['cancelled', 'missed', 'infrastructure_failed']);

it('requires matching broker UUID and attempt on a restored failed hook', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team);
    $work = runnerWork($team)->sole();
    $uuid = (string) Str::uuid();
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($team->id, $work->id, $uuid, 1);
    expect($ledger->failDelivery($team->id, [$work->id], (string) Str::uuid(), 1))->toBe(0)
        ->and($ledger->failDelivery($team->id, [$work->id], $uuid, 2))->toBe(0);
    $job = new DeliverCheckBatch($team->id, [$work->id => $monitor->id], 'eu');
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('payload')->andReturn(['uuid' => $uuid]);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);
    $job->failed(new RuntimeException('private token must never be recorded'));
    $job->failed(null);
    expect($ledger->finish($acquired, false, runnerOutcome($acquired)))->toBeFalse()
        ->and(DB::table('check_results')->count())->toBe(1)
        ->and(DB::table('check_results')->value('error_message'))->not->toContain('private token');
});

it('requires regional agreement before advancing the current slot summary', function (bool $agree): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team, attributes: ['regions_json' => new MonitorRegions([MonitorRegion::Eu, MonitorRegion::Us])]);
    $rows = runnerWork($team);
    $ledger = app(ExecutionLedger::class);
    $eu = $ledger->acquire($team->id, $rows[0]->id);
    $us = $ledger->acquire($team->id, $rows[1]->id);
    $ledger->finish($eu, false, runnerOutcome($eu));
    expect(DB::table('monitors')->value('last_status'))->toBe('unknown');
    $ledger->finish($us, false, runnerOutcome($us, $agree ? MonitorStatus::Up : MonitorStatus::Down));
    expect(DB::table('monitors')->value('last_status'))->toBe($agree ? 'up' : 'unknown')
        ->and(DB::table('check_results')->count())->toBe(2);
})->with([true, false]);

it('keeps historical observations after a definition change without applying their summary', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team);
    $work = runnerWork($team)->sole();
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($team->id, $work->id);
    DB::table('monitors')->where('id', $monitor->id)->increment('execution_revision');
    $ledger->finish($acquired, false, runnerOutcome($acquired));
    expect(DB::table('check_results')->value('monitor_revision'))->toBe($work->monitor_revision)
        ->and(DB::table('monitors')->value('last_status'))->toBe('unknown')
        ->and(DB::table('monitors')->value('last_checked_at'))->toBeNull();
});

it('prepares deterministic UTC month boundaries idempotently and forbids alternate result identities', function (): void {
    $partitions = app(ResultPartitions::class);
    expect($partitions->ensure(CarbonImmutable::parse('2027-01-01T00:30:00+01:00')))->toBe('check_results_2026_12')
        ->and($partitions->ensure(CarbonImmutable::parse('2027-01-01T00:00:00Z')))->toBe('check_results_2027_01')
        ->and($partitions->prepare())->toBe(3);
    $team = Team::factory()->business()->create();
    runnerMonitor($team);
    $work = runnerWork($team)->sole();
    $acquired = app(ExecutionLedger::class)->acquire($team->id, $work->id);
    app(ExecutionLedger::class)->finish($acquired, false, runnerOutcome($acquired));
    $row = (array) DB::table('check_results')->sole();
    unset($row['id']);
    $row['slot_at'] = '2027-01-01T00:00:00Z';
    expect(fn () => DB::transaction(fn () => DB::table('check_results')->insert($row)))->toThrow(Illuminate\Database\QueryException::class);
    $row['slot_at'] = $work->slot_at;
    $row['team_id']++;
    expect(fn () => DB::transaction(fn () => DB::table('check_results')->insert($row)))->toThrow(Illuminate\Database\QueryException::class);
});

it('rejects unsafe timing and oversized or duplicate-monitor batches before transport', function (string $case): void {
    $changes = match ($case) {
        'size' => ['monitor-scheduling.batch_size' => 3],
        'visibility' => ['queue.connections.redis.retry_after' => 50],
        'budget' => ['monitor-checks.resolution_timeout_ms' => 10000],
    };
    config($changes);
    expect(fn () => app(CheckRuntimeLimits::class)->batchSize())->toThrow(LogicException::class);
    Http::assertNothingSent();
})->with(['size', 'visibility', 'budget']);

it('executes a real mixed HTTP and keyword batch across a two-request socket barrier', function (): void {
    $hits = tempnam(sys_get_temp_dir(), 'runner-barrier-');
    $server = new Process([PHP_BINARY, base_path('tests/Fixtures/CheckLoopbackServer.php'), $hits], timeout: 15);
    try {
        $server->start();
        $server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY '));
        preg_match('/READY 127\.0\.0\.1:(\d+)/', $server->getOutput(), $matches);
        $origin = 'http://pinned.checker.test:'.$matches[1];
        app()->instance(HostResolver::class, new FakeHostResolver(['pinned.checker.test' => ['127.0.0.1']]));
        config(['outbound-security.allowed_cidrs' => ['127.0.0.1/32']]);
        // Replace the generic fake with real loopback-only acquisition for this barrier assertion.
        Http::swap(new Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::allowStrayRequests([$origin.'/*']);
        $team = Team::factory()->business()->create();
        $map = [];
        foreach (['http', 'keyword'] as $type) {
            $monitor = runnerMonitor($team, $type, ['timeout_ms' => 2000]);
            app(TenantContext::class)->run($team->id, function () use ($monitor, $origin, $type): void {
                $configuration = new App\Data\Monitoring\HttpMonitorConfiguration($origin.'/barrier-'.$type);
                $monitor->applyConfiguration($type === 'http' ? $configuration : new App\Data\Monitoring\KeywordMonitorConfiguration($configuration, 'both-arrived'));
                $monitor->save();
            });
        }
        foreach (runnerWork($team) as $work) {
            $map[$work->id] = $work->monitor_id;
        }
        app(RunMonitorChecks::class)->consumeBatch($team->id, $map, 'eu', null, null);
        expect(DB::table('check_results')->where('status', 'up')->count())->toBe(2)
            ->and(file($hits, FILE_IGNORE_NEW_LINES))->toHaveCount(2);
    } finally {
        $server->stop();
        unlink($hits);
    }
});

it('settles a real Redis worker exception or timeout using fresh queue ownership and recovers SIGKILL', function (string $mode): void {
    $fixture = Tests\Support\CommittedTeamFixture::freeTeam(1);
    $process = null;
    $queue = Queue::connection('redis');
    $name = 'checks:probe-'.Str::uuid();
    try {
        $work = $fixture->committed(function () use ($fixture): CheckExecution {
            DB::table('monitors')->where('team_id', $fixture->teamId)->update(['next_check_at' => CarbonImmutable::now()->subSecond()]);
            app(ReserveMonitorChecks::class)->run();
            return app(TenantContext::class)->run($fixture->teamId, fn (): CheckExecution => CheckExecution::query()->sole());
        });
        $job = new DeliverCheckBatch($fixture->teamId, [$work->id => $work->monitor_id], 'eu');
        $job->timeout = 1;
        $queue->push($job, '', $name);
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/RunCheckWorkerProbe.php'), $name, $mode], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'signalbox_test',
            'DB_USERNAME' => 'signalbox_test',
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'DB_URL' => '',
            'REDIS_HOST' => (string) config('database.redis.default.host'),
            'REDIS_URL' => '',
            'REDIS_PASSWORD' => 'null',
            'REDIS_PREFIX' => 'signalbox_test:',
            'REDIS_DB' => '0',
            'REDIS_CACHE_DB' => '1',
            'CACHE_STORE' => 'array',
            'LOG_CHANNEL' => 'null',
            'CHECK_WORKER_NOW' => CarbonImmutable::now()->toIso8601String(),
        ], timeout: 12);
        $process->start();
        if (!$process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ACQUIRED'))) {
            throw new RuntimeException('Worker did not acquire: '.$process->getOutput().$process->getErrorOutput());
        }
        if ($mode === 'kill') {
            $process->signal(9);
            $process->wait();
            expect($fixture->connection->table('check_executions')->where('id', $work->id)->value('state'))->toBe('running');
            $deadline = $fixture->connection->table('check_executions')->where('id', $work->id)->value('deadline_at');
            CarbonImmutable::setTestNow(CarbonImmutable::parse($deadline));
            $fixture->committed(fn (): bool => app(ExecutionLedger::class)->recover($fixture->teamId, $work->id));
        } else {
            try {
                $process->wait();
            } catch (Symfony\Component\Process\Exception\ProcessSignaledException $exception) {
                // Laravel terminates a timed-out worker with SIGKILL after running the fresh failed hook.
                expect($mode)->toBe('timeout');
                expect($exception->getProcess()->getTermSignal())->toBe(9);
            }
        }
        $row = $fixture->connection->table('check_results')->where('execution_id', $work->id)->sole();
        expect($row->kind)->toBe('infrastructure_failed')
            ->and($row->status)->toBe('unknown')
            ->and($row->checked_at)->toBeNull()
            ->and($row->error_message)->not->toContain('private worker')
            ->and($fixture->connection->table('check_executions')->where('id', $work->id)->value('queue_uuid'))->not->toBeNull();
    } finally {
        $process?->stop();
        $queue->clear($name);
        $fixture->cleanup();
    }
})->with(['exception', 'timeout', 'kill']);

it('retains unacquired work when a shared monitor lock skips a Redis delivery', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team);
    $work = runnerWork($team)->sole();
    $envelope = new DeliverCheckBatch($team->id, [$work->id => $monitor->id], 'eu');
    $middleware = $envelope->middleware()[1];
    $lock = Illuminate\Support\Facades\Cache::lock($middleware->getLockKey($envelope), 55);
    $queue = Queue::connection('redis');
    $name = 'checks:locked-'.Str::uuid();
    try {
        expect($lock->get())->toBeTrue();
        $queue->push($envelope, '', $name);
        $queue->pop($name)->fire();
        expect(DB::table('check_executions')->value('state'))->toBe('pending')
            ->and(DB::table('check_results')->count())->toBe(0);
        Http::assertNothingSent();
        $lock->release();
        $queue->push($envelope, '', $name);
        $queue->pop($name)->fire();
        expect(DB::table('check_results')->value('status'))->toBe('up');
    } finally {
        $lock->release();
        $queue->clear($name);
    }
});

it('publishes bounded same-team regional batches with distinct monitor identities and scalar payloads', function (): void {
    $team = Team::factory()->business()->create();
    foreach (range(1, 3) as $ignored) {
        runnerMonitor($team);
    }
    runnerWork($team);
    $queue = Queue::connection('redis');
    $queue->clear('checks:eu');
    try {
        expect(app(App\Services\Monitoring\Scheduling\PublishMonitorChecks::class)->run())->toBe(3)
            ->and($queue->size('checks:eu'))->toBe(2);
        $sizes = [];
        foreach (range(1, 2) as $ignored) {
            $delivery = $queue->pop('checks:eu');
            $payload = $delivery->payload();
            expect($payload['data']['command'])->not->toContain('example.invalid', 'headers', 'configuration');
            $job = unserialize($payload['data']['command']);
            expect($job)->toBeInstanceOf(DeliverCheckBatch::class)
                ->and($job->teamId)->toBe($team->id)
                ->and($job->region)->toBe('eu');
            $sizes[] = count($job->executions);
            $delivery->fire();
        }
        sort($sizes);
        expect($sizes)->toBe([1, 2])
            ->and(DB::table('check_results')->count())->toBe(3);
    } finally {
        $queue->clear('checks:eu');
    }
});

it('rolls back the ledger transition and summary when result insertion fails', function (): void {
    $team = Team::factory()->business()->create();
    runnerMonitor($team);
    $work = runnerWork($team)->sole();
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($team->id, $work->id);
    // A mismatched observation must abort the enclosing transaction rather than partially complete its owner.
    $outcome = new CheckOutcome(new CheckContext($team->id, $work->monitor_id, $work->id + 1, MonitorRegion::Eu, $work->slot_at), MonitorStatus::Up, CarbonImmutable::now(), null, 200);
    expect(fn () => $ledger->finish($acquired, false, $outcome))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('check_executions')->value('state'))->toBe('running')
        ->and(DB::table('check_results')->count())->toBe(0)
        ->and(DB::table('monitors')->value('last_result_slot_at'))->toBeNull();
    $ledger->finish($acquired, false, runnerOutcome($acquired));
    expect(DB::table('check_results')->value('response_time_ms'))->toBe(13);
    expect(fn () => DB::transaction(fn () => DB::table('check_results')->update(['status' => 'down'])))->toThrow(Illuminate\Database\QueryException::class);
});

it('keeps a newer slot summary when an earlier acquired observation finishes last', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team);
    $first = runnerWork($team)->sole();
    $ledger = app(ExecutionLedger::class);
    $old = $ledger->acquire($team->id, $first->id);
    // Compress time using two nearby valid fixture slots while retaining real ledger uniqueness and fencing.
    $next = $first->slot_at->addSecond();
    $attributes = $first->getRawOriginal();
    unset($attributes['id']);
    $attributes['slot_at'] = $next;
    $attributes['expires_at'] = CarbonImmutable::now()->addSeconds(100);
    $attributes['created_at'] = CarbonImmutable::now();
    $id = DB::table('check_executions')->insertGetId($attributes);
    $new = $ledger->acquire($team->id, $id);
    $ledger->finish($new, false, runnerOutcome($new));
    $ledger->finish($old, false, runnerOutcome($old, MonitorStatus::Down));
    expect(DB::table('monitors')->value('last_status'))->toBe('up')
        ->and(CarbonImmutable::parse(DB::table('monitors')->value('last_result_slot_at'))->equalTo($next))->toBeTrue()
        ->and(DB::table('check_results')->count())->toBe(2);
});

it('partitions by the immutable slot while retaining a next-month actual observation time', function (): void {
    $team = Team::factory()->business()->create();
    runnerMonitor($team);
    $work = runnerWork($team)->sole();
    DB::table('check_executions')->where('id', $work->id)->update([
        'slot_at' => '2026-10-31T23:59:59Z',
        'expires_at' => '2026-11-01T00:01:00Z',
    ]);
    CarbonImmutable::setTestNow('2026-11-01T00:00:02Z');
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($team->id, $work->id);
    $ledger->finish($acquired, false, runnerOutcome($acquired));
    $row = DB::selectOne('SELECT tableoid::regclass::text AS partition, checked_at, slot_at FROM check_results WHERE execution_id = ?', [$work->id]);
    expect($row->partition)->toBe('check_results_2026_10')
        ->and(CarbonImmutable::parse($row->checked_at)->month)->toBe(11)
        ->and(CarbonImmutable::parse($row->slot_at)->month)->toBe(10);
});

it('commits an infrastructure result for malformed private input without republishing acquired work', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = runnerMonitor($team);
    $work = runnerWork($team)->sole();
    DB::table('monitors')->where('id', $monitor->id)->update(['url' => 'synthetic-invalid-ciphertext']);
    expect(fn () => app(RunMonitorChecks::class)->consumeBatch($team->id, [$work->id => $monitor->id], 'eu', null, null))->toThrow(Illuminate\Contracts\Encryption\DecryptException::class)
        ->and(DB::table('check_executions')->value('state'))->toBe('infrastructure_failed')
        ->and(DB::table('check_results')->value('kind'))->toBe('infrastructure_failed');
    Http::assertNothingSent();
});

it('preserves a completed heartbeat cohort when the following HTTP cohort fails in infrastructure', function (): void {
    $team = Team::factory()->business()->create();
    runnerMonitor($team, 'heartbeat');
    runnerMonitor($team);
    $map = [];
    foreach (runnerWork($team) as $work) {
        $map[$work->id] = $work->monitor_id;
    }
    app()->instance(HostResolver::class, new class () implements HostResolver {
        /**
         * @param string $host Synthetic destination.
         * @param int|null $timeoutMs Unused immediate failure budget.
         * @return list<string> Never returns a target address.
         */
        public function resolve(string $host, ?int $timeoutMs = null): array
        {
            throw new RuntimeException('Synthetic coordinator infrastructure failure.');
        }
    });
    expect(fn () => app(RunMonitorChecks::class)->consumeBatch($team->id, $map, 'eu', null, null))->toThrow(RuntimeException::class)
        ->and(DB::table('check_results')->where('kind', 'observed')->count())->toBe(1)
        ->and(DB::table('check_results')->where('kind', 'infrastructure_failed')->count())->toBe(1);
    Http::assertNothingSent();
});
