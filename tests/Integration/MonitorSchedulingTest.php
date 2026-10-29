<?php

declare(strict_types=1);

/** Exercise the execution outbox, cadence and fenced recovery against isolated PostgreSQL and Redis. */

use App\Contracts\Monitoring\CheckWorkConsumer;
use App\Contracts\Monitoring\CheckWorkPublisher;
use App\Data\Monitoring\MonitorRegions;
use App\Enums\Monitoring\MonitorRegion;
use App\Exceptions\MissingTenantContext;
use App\Jobs\DeliverCheckExecution;
use App\Models\CheckExecution;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Monitoring\Scheduling\MonitorSlots;
use App\Services\Monitoring\Scheduling\PublishMonitorChecks;
use App\Services\Monitoring\Scheduling\RecoverMonitorChecks;
use App\Services\Monitoring\Scheduling\RedisCheckWorkPublisher;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\RecordingCheckPublisher;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL and Redis.');
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23T12:00:00Z'));
    config(['monitor-scheduling.enabled' => true, 'monitor-scheduling.chunk_size' => 2]);
    $this->broker = new RecordingCheckPublisher();
    app()->instance(CheckWorkPublisher::class, $this->broker);
});
afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Explicit owner.
 * @param array<string, mixed> $attributes Scheduling overrides for synthetic monitors.
 * @return Monitor Persisted tenant-owned monitor.
 */
function schedulingMonitor(Team $team, array $attributes = []): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create($attributes));
}

/**
 * @param Team $team Explicit execution owner.
 * @return \Illuminate\Database\Eloquent\Collection<int, CheckExecution> Tenant-scoped outbox rows in identity order.
 */
function schedulingWork(Team $team): Illuminate\Database\Eloquent\Collection
{
    return app(TenantContext::class)->run($team->id, fn () => CheckExecution::query()->orderBy('id')->get());
}

/**
 * @param Team $team Owner whose active monitor is scheduled immediately.
 * @return CheckExecution Single regional work identity created through production reservation.
 */
function reserveSchedulingWork(Team $team): CheckExecution
{
    $monitor = schedulingMonitor($team);
    $slot = app(MonitorSlots::class)->latest($monitor->id, $monitor->interval_seconds, CarbonImmutable::now());
    CarbonImmutable::setTestNow($slot->addSecond());
    DB::table('monitors')->where('id', $monitor->id)->update(['next_check_at' => $slot]);
    app(ReserveMonitorChecks::class)->run();

    return schedulingWork($team)->sole();
}

it('initializes future slots across chunks and tenants without creating work or decrypting secrets', function (): void {
    $alpha = Team::factory()->business()->create();
    $beta = Team::factory()->pro()->create();
    $ids = [];

    foreach ([$alpha, $beta] as $team) {
        foreach (range(1, 3) as $ignored) {
            $monitor = schedulingMonitor($team);
            $ids[$monitor->id] = $team->id;
            // Trusted raw corruption proves the scheduler does not decrypt HTTP secrets.
            DB::table('monitors')->where('id', $monitor->id)->update(['url' => 'not-decryptable']);
        }
    }

    expect(app(ReserveMonitorChecks::class)->run())->toBe(0);

    foreach ($ids as $id => $teamId) {
        $at = DB::table('monitors')->where('id', $id)->value('next_check_at');
        expect(CarbonImmutable::parse($at)->equalTo(app(MonitorSlots::class)->next($id, 300, CarbonImmutable::now())))->toBeTrue();
    }

    expect(schedulingWork($alpha))->toBeEmpty()
        ->and(schedulingWork($beta))->toBeEmpty();
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('reserves only the newest due slot per region and does not drift or dispatch twice', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = schedulingMonitor($team, [
        'interval_seconds' => 30,
        'next_check_at' => CarbonImmutable::now()->subHours(2),
        'regions_json' => new MonitorRegions([MonitorRegion::Eu, MonitorRegion::Us]),
    ]);
    $slots = app(MonitorSlots::class);
    $now = CarbonImmutable::now();
    $expected = $slots->latest($monitor->id, 30, $now);

    expect(app(ReserveMonitorChecks::class)->run())->toBe(2)
        ->and(app(ReserveMonitorChecks::class)->run())->toBe(0);
    $work = schedulingWork($team);
    expect($work->pluck('region')->all())->toBe(['eu', 'us']);

    foreach ($work as $row) {
        expect($row->slot_at->equalTo($expected))->toBeTrue()
            ->and($row->expires_at->equalTo($expected->addSeconds(30)))->toBeTrue()
            ->and($row->state)->toBe('pending');
    }

    CarbonImmutable::setTestNow($expected->addSeconds(30));
    expect(app(ReserveMonitorChecks::class)->run())->toBe(2)
        ->and(schedulingWork($team))->toHaveCount(4);
});

it('skips paused suspended over-quota and too-fast monitors without changing their configuration', function (): void {
    $team = Team::factory()->free()->trialEnded()->create();

    foreach (range(1, 7) as $position) {
        schedulingMonitor($team, [
            'next_check_at' => CarbonImmutable::now()->subSecond(),
            'is_paused' => $position === 1,
            'is_plan_suspended' => $position === 2,
            'interval_seconds' => $position === 3 ? 30 : 300,
        ]);
    }

    expect(app(ReserveMonitorChecks::class)->run())->toBe(2)
        ->and(schedulingWork($team))->toHaveCount(2)
        ->and(DB::table('monitors')->where('team_id', $team->id)->where('interval_seconds', 30)->count())->toBe(1)
        ->and(DB::table('monitors')->where('team_id', $team->id)->where('is_plan_suspended', true)->count())->toBe(1);
});

it('reinitializes a cleared slot using the current interval without immediate work', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = schedulingMonitor($team, ['interval_seconds' => 30]);
    app(ReserveMonitorChecks::class)->run();
    DB::table('monitors')->where('id', $monitor->id)->update(['interval_seconds' => 60, 'next_check_at' => null]);

    expect(app(ReserveMonitorChecks::class)->run())->toBe(0)
        ->and(schedulingWork($team))->toBeEmpty();
    $next = CarbonImmutable::parse(DB::table('monitors')->where('id', $monitor->id)->value('next_check_at'));
    expect($next->equalTo(app(MonitorSlots::class)->next($monitor->id, 60, CarbonImmutable::now())))->toBeTrue();
});

it('rolls back reservations and cadence advancement together before broker publication', function (): void {
    $team = Team::factory()->create();
    $due = CarbonImmutable::now()->subMinute();
    $monitor = schedulingMonitor($team, ['next_check_at' => $due]);
    DB::beginTransaction();

    try {
        expect(app(ReserveMonitorChecks::class)->run())->toBe(1)
            ->and($this->broker->messages)->toBeEmpty();
    } finally {
        DB::rollBack();
    }

    expect(schedulingWork($team))->toBeEmpty()
        ->and(CarbonImmutable::parse(DB::table('monitors')->where('id', $monitor->id)->value('next_check_at'))->equalTo($due))->toBeTrue();
});

it('publishes committed work after a restart and retries confirmed but unacquired deliveries', function (): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    expect($this->broker->messages)->toBeEmpty();

    expect(app(PublishMonitorChecks::class)->run())->toBe(1)
        ->and(app(PublishMonitorChecks::class)->run())->toBe(0);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    expect(app(PublishMonitorChecks::class)->run())->toBe(1)
        ->and($this->broker->messages)->toHaveCount(2)
        ->and(array_unique(array_column($this->broker->messages, 'execution')))->toBe([$work->id]);
});

it('recovers an uncertain send without duplicate acquisition or target retries', function (): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    $this->broker->afterSend = static fn () => throw new RuntimeException('Uncertain send');

    expect(app(PublishMonitorChecks::class)->run())->toBe(0);
    $this->broker->afterSend = null;
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10));
    expect(app(PublishMonitorChecks::class)->run())->toBe(1);

    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($team->id, $work->id);
    expect($acquired)->not->toBeNull()
        ->and($ledger->acquire($team->id, $work->id))->toBeNull()
        ->and($ledger->finish($acquired))->toBeTrue()
        ->and($ledger->finish($acquired))->toBeFalse();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10));
    expect(app(PublishMonitorChecks::class)->run())->toBe(0)
        ->and(schedulingWork($team)->sole()->state)->toBe('completed');
});

it('recovers a publisher crash before send and rejects acknowledgements from old leases', function (): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    $ledger = app(ExecutionLedger::class);
    $old = $ledger->leasePublication($team->id, $work->id);
    expect($old)->not->toBeNull()
        ->and($ledger->leasePublication($team->id, $work->id))->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10));
    $new = $ledger->leasePublication($team->id, $work->id);
    expect($new)->not->toBeNull()
        ->and($new->publication_token)->not->toBe($old->publication_token)
        ->and($ledger->acknowledge($old))->toBeFalse()
        ->and($ledger->acknowledge($new))->toBeTrue();
});

it('expires an unstarted execution at its exact validity boundary without inventing target failure', function (): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    CarbonImmutable::setTestNow($work->expires_at);

    expect(app(PublishMonitorChecks::class)->run())->toBe(0)
        ->and(app(ExecutionLedger::class)->acquire($team->id, $work->id))->toBeNull()
        ->and(schedulingWork($team)->sole()->state)->toBe('missed')
        ->and(DB::table('monitors')->where('id', $work->monitor_id)->value('last_status'))->toBe('unknown')
        ->and(app(RecoverMonitorChecks::class)->run())->toBe(0);
});

it('recovers expired pending work through the periodic reconciler', function (): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    CarbonImmutable::setTestNow($work->expires_at);

    expect(app(RecoverMonitorChecks::class)->run())->toBe(1)
        ->and(app(RecoverMonitorChecks::class)->run())->toBe(0)
        ->and(schedulingWork($team)->sole()->state)->toBe('missed');
});

it('terminalizes an abandoned acquisition once and fences late completion', function (bool $recoverFirst): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($team->id, $work->id);
    $forged = clone $acquired;
    $forged->acquisition_token = (string) Str::uuid();
    expect($ledger->finish($forged))->toBeFalse();

    CarbonImmutable::setTestNow($acquired->deadline_at);

    if ($recoverFirst) {
        expect(app(RecoverMonitorChecks::class)->run())->toBe(1);
    }

    expect($ledger->finish($acquired))->toBeFalse()
        ->and(app(RecoverMonitorChecks::class)->run())->toBe(0)
        ->and($ledger->acquire($team->id, $work->id))->toBeNull()
        ->and(schedulingWork($team)->sole()->state)->toBe('infrastructure_failed');
})->with([true, false]);

it('revalidates configuration pause and plan after publication before admitting a consumer', function (string $change): void {
    $team = Team::factory()->business()->create();
    $work = reserveSchedulingWork($team);
    app(PublishMonitorChecks::class)->run();

    match ($change) {
        'pause' => DB::table('monitors')->where('id', $work->monitor_id)->update(['is_paused' => true]),
        'suspend' => DB::table('monitors')->where('id', $work->monitor_id)->update(['is_plan_suspended' => true]),
        'configuration' => app(App\Actions\Monitoring\ManageMonitors::class)->update($team->owner, $team->id, $work->monitor_id, ['expected_status' => 204]),
        'interval' => app(App\Actions\Monitoring\ManageMonitors::class)->update($team->owner, $team->id, $work->monitor_id, ['interval_seconds' => 30]),
        'plan' => config(['plans.business.monitors' => 1]),
        'pause-resume' => (function () use ($team, $work): void {
            $actions = app(App\Actions\Monitoring\ManageMonitors::class);
            $actions->pause($team->owner, $team->id, $work->monitor_id);
            $actions->resume($team->owner, $team->id, $work->monitor_id);
        })(),
    };

    if ($change === 'plan') {
        schedulingMonitor($team, ['created_at' => CarbonImmutable::now()->subDay()]);
    }

    expect(app(ExecutionLedger::class)->acquire($team->id, $work->id))->toBeNull()
        ->and(schedulingWork($team)->sole()->state)->toBe('cancelled');
})->with(['pause', 'suspend', 'configuration', 'interval', 'plan', 'pause-resume']);

it('rejects foreign envelope identities and restores the previous tenant after transitions', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $work = reserveSchedulingWork($alpha);
    $ledger = app(ExecutionLedger::class);

    app(TenantContext::class)->run($beta->id, function () use ($alpha, $beta, $work, $ledger): void {
        expect($ledger->acquire($beta->id, $work->id))->toBeNull()
            ->and($ledger->leasePublication($beta->id, $work->id))->toBeNull()
            ->and($ledger->recover($beta->id, $work->id))->toBeFalse()
            ->and($ledger->acquire($alpha->id, $work->id))->not->toBeNull()
            ->and(app(TenantContext::class)->requireTeamId())->toBe($beta->id);
    });
    expect(fn () => CheckExecution::query()->count())->toThrow(MissingTenantContext::class);
});

it('records infrastructure exceptions from an acquired delivery without retrying its consumer', function (): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    $consumer = Mockery::mock(CheckWorkConsumer::class);
    $consumer->shouldReceive('consume')->once()->andThrow(new RuntimeException('Synthetic worker failure'));
    $job = new DeliverCheckExecution($team->id, $work->id);

    expect(fn () => $job->handle(app(ExecutionLedger::class), $consumer))->toThrow(RuntimeException::class);
    $job->handle(app(ExecutionLedger::class), $consumer);

    expect(schedulingWork($team)->sole()->state)->toBe('infrastructure_failed')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(45);
});

it('routes scalar envelopes through real Redis and runs an idempotent test consumer', function (string $region): void {
    $team = Team::factory()->create();
    $monitor = schedulingMonitor($team, [
        'next_check_at' => CarbonImmutable::now()->subSecond(),
        'regions_json' => MonitorRegions::fromValues([$region]),
    ]);
    app(ReserveMonitorChecks::class)->run();
    $work = schedulingWork($team)->sole();
    $queue = Queue::connection('redis');
    $name = 'checks:'.$region;
    $consumer = Mockery::mock(CheckWorkConsumer::class);
    $consumer->shouldReceive('consume')->once()->andReturnUsing(function (CheckExecution $execution) use ($team): void {
        expect(app(TenantContext::class)->requireTeamId())->toBe($team->id);
        expect(app(ExecutionLedger::class)->finish($execution))->toBeTrue();
    });
    app()->instance(CheckWorkConsumer::class, $consumer);

    try {
        $publisher = app(RedisCheckWorkPublisher::class);
        $publisher->publish($team->id, $work->id, $region);
        $publisher->publish($team->id, $work->id, $region);
        expect($queue->size($name))->toBe(2);

        foreach (range(1, 2) as $ignored) {
            $job = $queue->pop($name);
            expect($job)->not->toBeNull();
            $payload = $job->payload();
            expect($payload['data']['command'])->not->toContain('example.invalid');
            $job->fire();
        }

        expect($queue->size($name))->toBe(0)
            ->and(schedulingWork($team)->sole()->state)->toBe('completed');
        expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
    } finally {
        $queue->clear($name);
    }
})->with(['eu', 'us']);

it('allows ordinary label edits without invalidating reserved configuration', function (): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    app(App\Actions\Monitoring\ManageMonitors::class)->update($team->owner, $team->id, $work->monitor_id, ['name' => 'Renamed monitor']);

    expect(app(ExecutionLedger::class)->acquire($team->id, $work->id))->not->toBeNull();
});

it('enforces execution identity ownership and acquisition shape in PostgreSQL', function (string $violation, string $sqlState): void {
    $team = Team::factory()->create();
    $work = reserveSchedulingWork($team);
    $attributes = $work->getRawOriginal();
    unset($attributes['id']);

    if ($violation === 'owner') {
        $attributes['team_id'] = Team::factory()->create()->id;
    } elseif ($violation === 'deadline') {
        $attributes['region'] = 'us';
        $attributes['state'] = 'running';
        $attributes['acquisition_token'] = (string) Str::uuid();
        $attributes['started_at'] = CarbonImmutable::now();
        $attributes['deadline_at'] = null;
    }

    try {
        DB::transaction(fn () => DB::table('check_executions')->insert($attributes));
        $this->fail('PostgreSQL must reject the invalid execution.');
    } catch (Illuminate\Database\QueryException $exception) {
        expect($exception->errorInfo[0])->toBe($sqlState);
    }
})->with([['duplicate', '23505'], ['owner', '23503'], ['deadline', '23514']]);

it('applies the exclusive trial boundary when reserving a fast monitor', function (bool $expired): void {
    $at = CarbonImmutable::now();
    $team = Team::factory()->free()->create([
        'trial_started_at' => $at->subDays(14),
        'trial_ends_at' => $expired ? $at : $at->addSecond(),
    ]);
    schedulingMonitor($team, ['interval_seconds' => 60, 'next_check_at' => $at->subSecond()]);

    expect(app(ReserveMonitorChecks::class)->run())->toBe($expired ? 0 : 1);
})->with([true, false]);

it('uses active operator entitlements and stops fast reservations at override expiry', function (): void {
    $at = CarbonImmutable::now();
    $team = Team::factory()->free()->trialEnded()->create();
    $actor = App\Models\User::factory()->create(['is_platform_admin' => true]);
    $override = new App\Models\TeamPlanOverride();
    $override->forceFill([
        'team_id' => $team->id,
        'kind' => 'comped',
        'plan' => 'business',
        'reason' => 'Synthetic scheduling entitlement',
        'expires_at' => $at->addMinute(),
        'granted_by' => $actor->id,
    ])->save();
    $monitor = schedulingMonitor($team, ['interval_seconds' => 30, 'next_check_at' => $at->subSecond()]);

    expect(app(ReserveMonitorChecks::class)->run())->toBe(1);
    CarbonImmutable::setTestNow($at->addMinute());
    expect(app(ReserveMonitorChecks::class)->run())->toBe(0)
        ->and(app(ExecutionLedger::class)->acquire($team->id, schedulingWork($team)->sole()->id))->toBeNull();
});

/**
 * Install a rollback-only PostgreSQL trigger that always rejects one monitor's execution writes.
 *
 * @param int $monitorId Poisoned identity, lower than the healthy one so it is visited first.
 * @param string $events Trigger events, such as INSERT or UPDATE.
 */
function poisonSchedulingMonitor(int $monitorId, string $events): void
{
    DB::statement("CREATE FUNCTION poison_monitor_execution() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic poison secret'; END \$\$");
    DB::statement("CREATE TRIGGER poison_monitor_execution BEFORE {$events} ON check_executions FOR EACH ROW WHEN (NEW.monitor_id = {$monitorId}) EXECUTE FUNCTION poison_monitor_execution()");
}

it('keeps serving later monitors and tenants when one item always fails', function (string $command): void {
    $poisonedTeam = Team::factory()->business()->create();
    $healthyTeam = Team::factory()->business()->create();
    $poisoned = schedulingMonitor($poisonedTeam);
    $healthy = schedulingMonitor($healthyTeam);
    $due = CarbonImmutable::now()->subSecond();
    DB::table('monitors')->update(['next_check_at' => $due]);
    if ($command !== 'monitors:dispatch') {
        app(ReserveMonitorChecks::class)->run();
        if ($command === 'monitors:recover') {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        }
    }
    poisonSchedulingMonitor($poisoned->id, $command === 'monitors:dispatch' ? 'INSERT' : 'UPDATE');
    Illuminate\Support\Facades\Log::spy();

    expect(Illuminate\Support\Facades\Artisan::call($command))->toBe(1);

    $states = DB::table('check_executions')->pluck('state', 'monitor_id');
    $logged = $command === 'monitors:dispatch' ? ['team_id' => $poisonedTeam->id, 'monitor_id' => $poisoned->id] : ['team_id' => $poisonedTeam->id];
    match ($command) {
        'monitors:dispatch' => expect($states->all())->toBe([$healthy->id => 'pending'])
            ->and(CarbonImmutable::parse(DB::table('monitors')->where('id', $poisoned->id)->value('next_check_at'))->equalTo($due))->toBeTrue(),
        'monitors:publish' => expect($this->broker->messages)->toHaveCount(1)
            ->and($this->broker->messages[0]['team'])->toBe($healthyTeam->id),
        'monitors:recover' => expect($states[$healthy->id])->toBe('missed')->and($states[$poisoned->id])->toBe('pending'),
    };
    Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->withArgs(
        static fn (string $message, array $context): bool => array_intersect_assoc($context, $logged) === $logged
            && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'synthetic poison'),
    );
})->with(['monitors:dispatch', 'monitors:publish', 'monitors:recover']);
