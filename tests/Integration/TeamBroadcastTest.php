<?php

declare(strict_types=1);

/** Exercise committed check/incident broadcasts, tenant correlation and failure isolation on guarded PostgreSQL. */

use App\Contracts\Monitoring\ResultObserver;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\MonitorRegions;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Events\Broadcasting\TeamUpdate;
use App\Events\Incidents\IncidentOpened;
use App\Events\Monitoring\CheckCompleted;
use App\Models\CheckExecution;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\Broadcasting\PublishTeamUpdates;
use App\Services\Incidents\IncidentLifecycle;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\RecordingNotificationPublisher;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', 'UTC'));
    config(['monitor-scheduling.enabled' => true, 'broadcasting.default' => 'reverb']);
    Queue::fake()->serializeAndRestore();
    $this->notifications = RecordingNotificationPublisher::bind();
    $this->team = Team::factory()->create();
    $this->monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create([
        'next_check_at' => CarbonImmutable::now()->subSecond(),
        'regions_json' => new MonitorRegions([MonitorRegion::Eu]),
    ]));
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Explicit owner of the due monitor.
 * @param Monitor $monitor Monitor with one immediately due simulated regional slot.
 * @return CheckExecution Latest reservation; no check transport or broker is invoked.
 */
function teamBroadcastWork(Team $team, Monitor $monitor): CheckExecution
{
    app(ReserveMonitorChecks::class)->run();

    return app(TenantContext::class)->run($team->id, fn (): CheckExecution => CheckExecution::query()
        ->where('monitor_id', $monitor->id)->orderByDesc('id')->firstOrFail());
}

/**
 * @param CheckExecution $work Pending execution within an explicit known owner.
 * @param MonitorStatus $status Synthetic observed status.
 * @param float|null $latency Measured milliseconds, null before a measurable transport.
 * @return CheckExecution Acquired ownership fence, useful for duplicate/late completion assertions.
 */
function teamBroadcastFinish(CheckExecution $work, MonitorStatus $status = MonitorStatus::Up, ?float $latency = 12.5): CheckExecution
{
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($work->team_id, $work->id);

    if ($acquired === null) {
        throw new LogicException('The synthetic broadcast execution could not be acquired.');
    }

    $context = new CheckContext($work->team_id, $work->monitor_id, $work->id, MonitorRegion::from($work->region), $work->slot_at);
    $outcome = new CheckOutcome(
        $context,
        $status,
        CarbonImmutable::now('UTC'),
        $latency,
        $status === MonitorStatus::Up ? 200 : 503,
        $status === MonitorStatus::Down ? CheckError::UnexpectedStatus : null,
    );
    expect($ledger->finish($acquired, outcome: $outcome))->toBeTrue();

    return $acquired;
}

/**
 * @param CheckExecution $work Committed correlation used to reconstruct a replayed internal event.
 * @param int|null $owner Optional forged tenant used by isolation cases.
 * @param int|null $monitor Optional forged monitor used by isolation cases.
 * @return CheckCompleted Scalar replay, with safe synthetic observation values.
 */
function teamBroadcastEvent(CheckExecution $work, ?int $owner = null, ?int $monitor = null): CheckCompleted
{
    return new CheckCompleted(
        $owner ?? $work->team_id,
        $monitor ?? $work->monitor_id,
        $work->id,
        MonitorRegion::from($work->region),
        $work->slot_at,
        $work->monitor_revision,
        CheckResultKind::Observed,
        MonitorStatus::Up,
        13,
        CarbonImmutable::now(),
        CarbonImmutable::now(),
    );
}

it('announces observed results with the same persisted latency and regional status', function (string $state, ?float $latency, ?int $expected): void {
    $work = teamBroadcastWork($this->team, $this->monitor);
    teamBroadcastFinish($work, MonitorStatus::from($state), $latency);
    $result = DB::table('check_results')->where('execution_id', $work->id)->sole();
    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, function (BroadcastEvent $job) use ($work, $state, $expected): bool {
        expect($job->event)->toBeInstanceOf(TeamUpdate::class);
        $data = $job->event->broadcastWith();
        expect($data['execution_id'])->toBe($work->id)
            ->and($data['monitor_id'])->toBe($work->monitor_id)
            ->and($data['status'])->toBe($state)
            ->and($data['response_time_ms'])->toBe($expected)
            ->and($data['kind'])->toBe('observed')
            ->and($job->event->broadcastOn()[0]->name)->toBe('private-team.'.$work->team_id);

        return $job->event->broadcastAs() === 'CheckCompleted';
    });
    expect($result->response_time_ms)->toBe($expected);
    Queue::assertPushed(BroadcastEvent::class, 1);
})->with([
    ['up', 12.5, 13],
    ['down', 23.4, 23],
    ['down', null, null],
]);

it('keeps non-observations unknown with no invented latency or check instant', function (string $kind): void {
    $work = teamBroadcastWork($this->team, $this->monitor);
    $ledger = app(ExecutionLedger::class);

    if ($kind === 'missed') {
        $this->travelTo($work->expires_at);
        expect($ledger->acquire($this->team->id, $work->id))->toBeNull();
    } elseif ($kind === 'cancelled') {
        app(TenantContext::class)->run($this->team->id, fn () => Monitor::query()->whereKey($this->monitor->id)->update(['is_paused' => true]));
        expect($ledger->acquire($this->team->id, $work->id))->toBeNull();
    } else {
        $acquired = $ledger->acquire($this->team->id, $work->id);
        expect($ledger->finish($acquired, true))->toBeTrue();
    }

    Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($kind): bool {
        $data = $job->event->broadcastWith();
        expect($data['kind'])->toBe($kind)
            ->and($data['status'])->toBe('unknown')
            ->and($data['response_time_ms'])->toBeNull()
            ->and($data['checked_at'])->toBeNull();

        return true;
    });
    Queue::assertPushed(BroadcastEvent::class, 1);
})->with(['missed', 'cancelled', 'infrastructure_failed']);

it('does not publish a second frame for duplicate terminal delivery', function (): void {
    $work = teamBroadcastWork($this->team, $this->monitor);
    $acquired = teamBroadcastFinish($work);
    expect(app(ExecutionLedger::class)->finish($acquired))->toBeFalse()
        ->and(DB::table('check_results')->where('execution_id', $work->id)->count())->toBe(1);
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('rolls back result and broadcast when its transactional observer fails', function (): void {
    $observer = Mockery::mock(ResultObserver::class);
    $observer->shouldReceive('recorded')->once()->andThrow(new LogicException('Synthetic observer failure.'));
    app()->instance(ResultObserver::class, $observer);
    $work = teamBroadcastWork($this->team, $this->monitor);
    expect(fn () => teamBroadcastFinish($work))->toThrow(LogicException::class, 'Synthetic observer failure.')
        ->and(DB::table('check_results')->where('execution_id', $work->id)->count())->toBe(0)
        ->and(DB::table('check_executions')->where('id', $work->id)->value('state'))->toBe('running');
    Queue::assertNothingPushed();
});

it('publishes incident lifecycle identities without mutable names private notes or destinations', function (): void {
    $incident = app(TenantContext::class)->run($this->team->id, function (): Incident {
        return DB::transaction(function (): Incident {
            Team::query()->whereKey($this->team->id)->lockForUpdate()->firstOrFail();
            $monitor = Monitor::query()->whereKey($this->monitor->id)->lockForUpdate()->firstOrFail();
            $monitor->forceFill(['name' => 'private monitor label'])->save();
            $lifecycle = app(IncidentLifecycle::class);
            $now = CarbonImmutable::now();
            $incident = $lifecycle->open($monitor, $now->subMinute(), CheckError::UnexpectedStatus, $now);
            $lifecycle->resolve($incident, null, 'private resolution note', $now);
            $lifecycle->reopen($incident, $now);

            return $incident;
        });
    });
    $jobs = Queue::pushed(BroadcastEvent::class);
    expect($jobs->map(fn (BroadcastEvent $job): string => $job->event->broadcastAs())->all())->toBe(['IncidentOpened', 'IncidentResolved', 'IncidentReopened']);

    foreach ($jobs as $job) {
        expect($job->event->broadcastWith())->toBe(['schema_version' => 1, 'monitor_id' => $this->monitor->id, 'incident_id' => $incident->id])
            ->and(serialize($job))->not->toContain('private resolution note', 'private monitor label', 'ModelIdentifier');
    }
});

it('isolates broker errors from completed observations incidents and notification intents', function (): void {
    $manager = Mockery::mock(BroadcastManager::class);
    $manager->shouldReceive('queue')->twice()->andThrow(new RuntimeException('private broker credentials'));
    app()->instance(BroadcastManager::class, $manager);
    Log::spy();
    app(TenantContext::class)->run($this->team->id, function (): void {
        Monitor::query()->whereKey($this->monitor->id)->update(['confirmation_threshold' => 1]);
        $channel = NotificationChannel::factory()->active()->create();
        EscalationPolicy::factory()->create(['is_default' => true, 'steps_json' => [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]]);
    });
    $work = teamBroadcastWork($this->team, $this->monitor);
    teamBroadcastFinish($work, MonitorStatus::Down);
    expect(DB::table('check_results')->where('execution_id', $work->id)->count())->toBe(1)
        ->and(DB::table('check_executions')->where('id', $work->id)->value('state'))->toBe('completed')
        ->and(DB::table('incidents')->where('team_id', $this->team->id)->count())->toBe(1)
        ->and(DB::table('notification_logs')->where('team_id', $this->team->id)->count())->toBe(1)
        ->and($this->notifications->sent)->toHaveCount(1);
    Log::shouldHaveReceived('warning')->twice()->with(
        'Team update publication failed; committed domain data remains authoritative.',
        ['team_id' => $this->team->id, 'monitor_id' => $this->monitor->id],
    );
});

it('rejects foreign missing and deleted correlations and restores the caller tenant', function (): void {
    $work = teamBroadcastWork($this->team, $this->monitor);
    teamBroadcastFinish($work);
    Queue::fake();
    $other = Team::factory()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn (): Monitor => Monitor::factory()->create());
    $foreignIncident = app(TenantContext::class)->run($other->id, fn (): Incident => Incident::factory()->create(['monitor_id' => $foreign->id]));
    $publisher = app(PublishTeamUpdates::class);
    app(TenantContext::class)->run($other->id, function () use ($work, $other, $foreign, $foreignIncident, $publisher): void {
        $publisher->publish(teamBroadcastEvent($work, $other->id));
        $publisher->publish(teamBroadcastEvent($work, $other->id, $foreign->id));
        $publisher->publish(new IncidentOpened($this->team->id, 999999, $this->monitor->id));
        $publisher->publish(new IncidentOpened($this->team->id, $foreignIncident->id, $this->monitor->id));
        expect(app(TenantContext::class)->requireTeamId())->toBe($other->id);
    });
    app(TenantContext::class)->run($this->team->id, fn () => $this->monitor->delete());
    $publisher->publish(teamBroadcastEvent($work));
    Queue::assertNothingPushed();
});

it('does not use Redis when broadcasting is disabled', function (?string $driver): void {
    config(['broadcasting.default' => $driver]);
    teamBroadcastFinish(teamBroadcastWork($this->team, $this->monitor));
    Queue::assertNothingPushed();
})->with([null, 'null']);

it('waits for a real outer commit and discards rolled-back check and incident events', function (string $source, bool $commit): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $previousManager = app('db.transactions');
    $manager = new DatabaseTransactionsManager();
    app()->instance('db.transactions', $manager);
    $fixture->connection->setTransactionManager($manager);

    try {
        $fixture->connection->beginTransaction();
        $fixture->committed(function () use ($fixture, $source): void {
            app(TenantContext::class)->run($fixture->teamId, function () use ($fixture, $source): void {
                $team = Team::query()->findOrFail($fixture->teamId);
                $monitor = Monitor::query()->firstOrFail();
                $monitor->forceFill(['next_check_at' => CarbonImmutable::now()->subSecond(), 'regions_json' => new MonitorRegions([MonitorRegion::Eu])])->save();

                if ($source === 'check') {
                    teamBroadcastFinish(teamBroadcastWork($team, $monitor));
                } else {
                    DB::transaction(function () use ($team, $monitor): void {
                        Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
                        Monitor::query()->whereKey($monitor->id)->lockForUpdate()->firstOrFail();
                        app(IncidentLifecycle::class)->open($monitor, CarbonImmutable::now()->subMinute(), CheckError::Timeout, CarbonImmutable::now());
                    });
                }
            });
        });
        Queue::assertNothingPushed();

        if ($commit) {
            $fixture->connection->commit();
        } else {
            $fixture->connection->rollBack();
        }

        Queue::assertPushed(BroadcastEvent::class, $commit ? 1 : 0);
    } finally {
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }

        app()->instance('db.transactions', $previousManager);
        $fixture->cleanup();
    }
})->with(['check', 'incident'])->with([true, false]);

it('broadcasts regional observations without presenting them as an agreed monitor status', function (): void {
    app(TenantContext::class)->run($this->team->id, fn () => $this->monitor->forceFill([
        'regions_json' => new MonitorRegions([MonitorRegion::Eu, MonitorRegion::Us]),
    ])->save());
    app(ReserveMonitorChecks::class)->run();
    $work = app(TenantContext::class)->run($this->team->id, fn () => CheckExecution::query()->where('monitor_id', $this->monitor->id)->orderBy('region')->get());
    teamBroadcastFinish($work[0], MonitorStatus::Up);
    expect(DB::table('monitors')->where('id', $this->monitor->id)->value('last_status'))->toBe('unknown');
    teamBroadcastFinish($work[1], MonitorStatus::Down);
    expect(DB::table('monitors')->where('id', $this->monitor->id)->value('last_status'))->toBe('unknown');
    $frames = Queue::pushed(BroadcastEvent::class)->map(fn (BroadcastEvent $job): array => $job->event->broadcastWith());
    expect($frames->pluck('region')->all())->toBe(['eu', 'us'])
        ->and($frames->pluck('status')->all())->toBe(['up', 'down']);
});

it('keeps an in-flight historical result tagged with its original monitor revision', function (): void {
    $work = teamBroadcastWork($this->team, $this->monitor);
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($this->team->id, $work->id);
    app(TenantContext::class)->run($this->team->id, fn () => Monitor::query()->whereKey($this->monitor->id)->increment('execution_revision'));
    $context = new CheckContext($work->team_id, $work->monitor_id, $work->id, MonitorRegion::Eu, $work->slot_at);
    expect($ledger->finish($acquired, outcome: new CheckOutcome($context, MonitorStatus::Up, CarbonImmutable::now(), 10.0, 200)))->toBeTrue()
        ->and(DB::table('monitors')->where('id', $this->monitor->id)->value('last_status'))->toBe('unknown');
    Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($work): bool {
        expect($job->event->broadcastWith()['monitor_revision'])->toBe($work->monitor_revision);

        return $job->event->broadcastAs() === 'CheckCompleted';
    });
});

it('keeps committed check state intact when the queued Reverb request fails', function (): void {
    $work = teamBroadcastWork($this->team, $this->monitor);
    teamBroadcastFinish($work);
    $job = Queue::pushed(BroadcastEvent::class)->sole();
    $factory = Mockery::mock(\Illuminate\Contracts\Broadcasting\Factory::class);
    $transport = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
    $factory->shouldReceive('connection')->once()->with(null)->andReturn($transport);
    $transport->shouldReceive('broadcast')->once()->andThrow(new RuntimeException('Synthetic Reverb unavailable.'));

    expect(fn () => $job->handle($factory))->toThrow(RuntimeException::class, 'Synthetic Reverb unavailable.')
        ->and(DB::table('check_executions')->where('id', $work->id)->value('state'))->toBe('completed')
        ->and(DB::table('check_results')->where('execution_id', $work->id)->count())->toBe(1)
        ->and(DB::table('monitors')->where('id', $this->monitor->id)->value('last_status'))->toBe('up');
});

it('normalizes equivalent slot instants before checking their committed correlation', function (): void {
    $work = teamBroadcastWork($this->team, $this->monitor);
    teamBroadcastFinish($work);
    Queue::fake();
    $event = new CheckCompleted(
        $work->team_id,
        $work->monitor_id,
        $work->id,
        MonitorRegion::Eu,
        $work->slot_at->setTimezone('Europe/Rome'),
        $work->monitor_revision,
        CheckResultKind::Observed,
        MonitorStatus::Up,
        13,
        CarbonImmutable::now('Europe/Rome'),
        CarbonImmutable::now('Europe/Rome'),
    );
    app(PublishTeamUpdates::class)->publish($event);
    Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($work): bool {
        return $job->event->broadcastWith()['slot_at'] === $work->slot_at->utc()->toIso8601String();
    });
    Queue::assertPushed(BroadcastEvent::class, 1);
});
