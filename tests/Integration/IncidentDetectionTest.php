<?php

declare(strict_types=1);

/** Verify regional confirmation and automatic incident opening, resolution and reopening through the real ledger. */

use App\Data\Incidents\SlotVerdict;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\MonitorRegions;
use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Incidents\SlotVerdictKind;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Models\CheckExecution;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\MonitorRegionSequence;
use App\Models\Team;
use App\Services\Incidents\IncidentAutomation;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }

    config(['monitor-scheduling.enabled' => true]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC'));
    Event::fake([IncidentOpened::class, IncidentReopened::class, IncidentResolved::class]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Owner of the monitor.
 * @param list<MonitorRegion> $regions Configured simulated regions.
 * @return Monitor HTTP monitor due immediately, with threshold three and a 300 second cadence.
 */
function detectionMonitor(Team $team, array $regions = [MonitorRegion::Eu]): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create([
        'regions_json' => new MonitorRegions($regions),
        'next_check_at' => CarbonImmutable::now('UTC')->subSecond(),
    ]));
}

/**
 * Reserve the next slot at its scheduled instant and terminate each regional execution as requested.
 *
 * @param Monitor $monitor Monitor whose next slot is due.
 * @param array<string, string> $outcomes Region value to up, down, infrastructure or pending (left unfinished).
 * @param CheckError $error Cause reported by down observations.
 * @return CarbonImmutable Slot that was executed.
 */
function detectionSlot(Monitor $monitor, array $outcomes, CheckError $error = CheckError::UnexpectedStatus): CarbonImmutable
{
    $tenants = app(TenantContext::class);
    $next = $tenants->run($monitor->team_id, fn (): ?CarbonImmutable => $monitor->fresh()?->next_check_at);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->max($next));
    app(ReserveMonitorChecks::class)->run();

    // Other due monitors may be reserved too; only this monitor's newest slot is executed here.
    $ledger = app(ExecutionLedger::class);
    $slot = $tenants->run($monitor->team_id, fn (): CarbonImmutable => CheckExecution::query()
        ->where('monitor_id', $monitor->id)->orderByDesc('slot_at')->firstOrFail()->slot_at);
    $works = $tenants->run($monitor->team_id, fn () => CheckExecution::query()
        ->where('monitor_id', $monitor->id)->where('slot_at', $slot)->where('state', 'pending')->orderBy('region')->get());
    expect($works)->toHaveCount(count($outcomes));

    foreach ($works as $work) {
        $outcome = $outcomes[$work->region];

        if ($outcome === 'pending') {
            continue;
        }

        $acquired = $ledger->acquire($monitor->team_id, $work->id);

        if ($outcome === 'infrastructure') {
            $ledger->finish($acquired, true);

            continue;
        }

        $status = $outcome === 'down' ? MonitorStatus::Down : MonitorStatus::Up;
        $context = new CheckContext($work->team_id, $work->monitor_id, $work->id, MonitorRegion::from($work->region), $work->slot_at);
        $ledger->finish($acquired, false, new CheckOutcome($context, $status, CarbonImmutable::now('UTC'), 10.0, $status === MonitorStatus::Down ? 503 : 200, $status === MonitorStatus::Down ? $error : null));
    }

    return $slot;
}

/**
 * @param Monitor $monitor Monitor to inspect.
 * @return list<Incident> Incidents of the monitor, oldest first.
 */
function detectionIncidents(Monitor $monitor): array
{
    return app(TenantContext::class)->run($monitor->team_id, fn (): array => Incident::query()
        ->where('monitor_id', $monitor->id)->orderBy('id')->get()->all());
}

/**
 * @param Monitor $monitor Monitor to inspect.
 * @return int Joint progress stored on the monitor.
 */
function detectionProgress(Monitor $monitor): int
{
    return (int) DB::table('monitors')->where('id', $monitor->id)->value('consecutive_failures');
}

it('does not open an incident before the third consecutive failure and opens exactly one at it', function (): void {
    $monitor = detectionMonitor(Team::factory()->create());
    $first = detectionSlot($monitor, ['eu' => 'down']);
    detectionSlot($monitor, ['eu' => 'down']);

    expect(detectionIncidents($monitor))->toBe([])
        ->and(detectionProgress($monitor))->toBe(2);
    Event::assertNotDispatched(IncidentOpened::class);

    $third = detectionSlot($monitor, ['eu' => 'down']);
    detectionSlot($monitor, ['eu' => 'down']);
    [$incident] = detectionIncidents($monitor);
    $entries = app(TenantContext::class)->run($monitor->team_id, fn () => $incident->updates()->get());

    expect(detectionIncidents($monitor))->toHaveCount(1)
        ->and($incident->status)->toBe(IncidentStatus::Investigating)
        ->and($incident->started_at->equalTo($first))->toBeTrue()
        ->and($incident->confirmed_at->equalTo($third))->toBeTrue()
        ->and($incident->cause)->toBe(CheckError::UnexpectedStatus)
        ->and($incident->severity)->toBe(IncidentSeverity::Major)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->author_id)->toBeNull()
        ->and($entries[0]->is_public)->toBeFalse()
        ->and(detectionProgress($monitor))->toBe(4);
    Event::assertDispatchedTimes(IncidentOpened::class, 1);
    Event::assertDispatched(IncidentOpened::class, fn (IncidentOpened $event): bool => $event->incidentId === $incident->id
        && $event->teamId === $monitor->team_id && $event->monitorId === $monitor->id);
});

it('restarts the count after an intermediate success or infrastructure failure', function (string $interruption): void {
    $monitor = detectionMonitor(Team::factory()->create());

    foreach (['down', 'down', $interruption, 'down', 'down'] as $outcome) {
        detectionSlot($monitor, ['eu' => $outcome]);
    }

    expect(detectionIncidents($monitor))->toBe([])
        ->and(detectionProgress($monitor))->toBe(2);
})->with(['up', 'infrastructure']);

it('resolves automatically on recovery and records the duration from the first failure', function (): void {
    $monitor = detectionMonitor(Team::factory()->create());
    $first = detectionSlot($monitor, ['eu' => 'down']);
    detectionSlot($monitor, ['eu' => 'down']);
    detectionSlot($monitor, ['eu' => 'down']);
    $recovery = detectionSlot($monitor, ['eu' => 'up']);
    detectionSlot($monitor, ['eu' => 'up']);
    [$incident] = detectionIncidents($monitor);

    expect($incident->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->resolved_at?->equalTo($recovery))->toBeTrue()
        ->and($incident->duration_seconds)->toBe((int) $first->diffInSeconds($recovery))
        ->and(app(TenantContext::class)->run($monitor->team_id, fn (): int => $incident->updates()->count()))->toBe(2)
        ->and(detectionProgress($monitor))->toBe(0);
    Event::assertDispatchedTimes(IncidentResolved::class, 1);
});

it('confirms two regions only when both reach the threshold in the same slot', function (): void {
    $monitor = detectionMonitor(Team::factory()->business()->create(), [MonitorRegion::Eu, MonitorRegion::Us]);

    foreach (range(1, 3) as $slot) {
        detectionSlot($monitor, ['eu' => 'down', 'us' => 'up']);
    }

    expect(detectionIncidents($monitor))->toBe([])
        ->and(detectionProgress($monitor))->toBe(0);

    detectionSlot($monitor, ['eu' => 'down', 'us' => 'down']);
    detectionSlot($monitor, ['eu' => 'down', 'us' => 'down']);

    // The third joint failure is incomplete until the second region reports.
    detectionSlot($monitor, ['eu' => 'down', 'us' => 'pending']);
    expect(detectionIncidents($monitor))->toBe([]);

    detectionSlot($monitor, ['eu' => 'down', 'us' => 'down']);
    expect(detectionIncidents($monitor))->toBe([])
        ->and(detectionProgress($monitor))->toBe(1);

    detectionSlot($monitor, ['eu' => 'down', 'us' => 'down']);
    detectionSlot($monitor, ['eu' => 'down', 'us' => 'down']);
    [$incident] = detectionIncidents($monitor);

    expect($incident->status)->toBe(IncidentStatus::Investigating)
        ->and(detectionProgress($monitor))->toBe(3);
});

it('resolves a two region incident only when both regions recover in the same slot', function (): void {
    $monitor = detectionMonitor(Team::factory()->business()->create(), [MonitorRegion::Eu, MonitorRegion::Us]);

    foreach (range(1, 3) as $slot) {
        detectionSlot($monitor, ['eu' => 'down', 'us' => 'down'], CheckError::Timeout);
    }

    detectionSlot($monitor, ['eu' => 'up', 'us' => 'down']);
    detectionSlot($monitor, ['eu' => 'up', 'us' => 'infrastructure']);
    expect(detectionIncidents($monitor)[0]->status)->toBe(IncidentStatus::Investigating)
        ->and(detectionIncidents($monitor)[0]->severity)->toBe(IncidentSeverity::Critical);

    detectionSlot($monitor, ['eu' => 'up', 'us' => 'up']);
    expect(detectionIncidents($monitor)[0]->status)->toBe(IncidentStatus::Resolved);
});

it('reopens within the window and opens a new incident beyond it', function (int $window, int $incidents, int $reopened): void {
    config(['incidents.reopen_window_seconds' => $window]);
    $monitor = detectionMonitor(Team::factory()->create());

    foreach (['down', 'down', 'down', 'up', 'down', 'down', 'down'] as $outcome) {
        detectionSlot($monitor, ['eu' => $outcome]);
    }

    $all = detectionIncidents($monitor);

    // Resolution and the next confirmation are exactly three 300 second slots apart.
    expect($all)->toHaveCount($incidents)
        ->and($all[0]->reopened_count)->toBe($reopened)
        ->and(end($all)->status)->toBe(IncidentStatus::Investigating);
    Event::assertDispatchedTimes(IncidentReopened::class, $reopened);
    Event::assertDispatchedTimes(IncidentOpened::class, $incidents);
})->with([
    'at the window boundary' => [900, 1, 1],
    'just beyond the window' => [899, 2, 0],
]);

it('ignores results of a superseded monitor revision', function (): void {
    $team = Team::factory()->create();
    $monitor = detectionMonitor($team);
    detectionSlot($monitor, ['eu' => 'down']);
    detectionSlot($monitor, ['eu' => 'down']);

    $tenants = app(TenantContext::class);
    $next = $tenants->run($team->id, fn (): ?CarbonImmutable => $monitor->fresh()?->next_check_at);
    CarbonImmutable::setTestNow($next);
    app(ReserveMonitorChecks::class)->run();
    $work = $tenants->run($team->id, fn () => CheckExecution::query()->where('state', 'pending')->sole());
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($team->id, $work->id);
    DB::table('monitors')->where('id', $monitor->id)->increment('execution_revision');
    $context = new CheckContext($team->id, $monitor->id, $work->id, MonitorRegion::Eu, $work->slot_at);
    $ledger->finish($acquired, false, new CheckOutcome($context, MonitorStatus::Down, CarbonImmutable::now('UTC'), 10.0, 503, CheckError::UnexpectedStatus));

    $sequence = $tenants->run($team->id, fn () => MonitorRegionSequence::query()->sole());

    expect(detectionIncidents($monitor))->toBe([])
        ->and($sequence->consecutive_failures)->toBe(2)
        ->and($sequence->monitor_revision)->toBe(1);
});

it('keeps sequences and incidents inside the owning tenant', function (): void {
    $failing = detectionMonitor(Team::factory()->create());
    $healthy = detectionMonitor(Team::factory()->create());

    // Each monitor runs consecutive slots; interleaving them would skip slots and interrupt the sequence.
    foreach (range(1, 3) as $slot) {
        detectionSlot($failing, ['eu' => 'down']);
    }

    foreach (range(1, 3) as $slot) {
        detectionSlot($healthy, ['eu' => 'up']);
    }

    $tenants = app(TenantContext::class);

    expect($tenants->run($healthy->team_id, fn (): array => [Incident::query()->count(), IncidentUpdate::query()->count(), MonitorRegionSequence::query()->count()]))
        ->toBe([0, 0, 1])
        ->and($tenants->run($failing->team_id, fn (): array => [Incident::query()->count(), MonitorRegionSequence::query()->sole()->team_id]))
        ->toBe([1, $failing->team_id]);
});

it('treats a repeated confirmation as idempotent and dispatches nothing when the transaction rolls back', function (): void {
    $monitor = detectionMonitor(Team::factory()->create());
    $verdict = new SlotVerdict(SlotVerdictKind::Confirmed, CarbonImmutable::now('UTC'), CheckError::Timeout);
    $automation = app(IncidentAutomation::class);
    $tenants = app(TenantContext::class);

    $tenants->run($monitor->team_id, function () use ($automation, $monitor, $verdict): void {
        try {
            DB::transaction(function () use ($automation, $monitor, $verdict): never {
                $automation->apply($monitor, $verdict, CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC'));

                throw new RuntimeException('Synthetic rollback.');
            });
        } catch (RuntimeException) {
        }
    });

    expect(detectionIncidents($monitor))->toBe([]);
    Event::assertNotDispatched(IncidentOpened::class);

    $tenants->run($monitor->team_id, function () use ($automation, $monitor, $verdict): void {
        DB::transaction(fn () => $automation->apply($monitor, $verdict, CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')));
        DB::transaction(fn () => $automation->apply($monitor, $verdict, CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')));
    });

    expect(detectionIncidents($monitor))->toHaveCount(1);
    Event::assertDispatchedTimes(IncidentOpened::class, 1);
});

/**
 * @param Monitor $monitor Monitor under maintenance.
 * @param CarbonImmutable $start Inclusive window start.
 * @param CarbonImmutable $end Exclusive window end.
 */
function detectionMaintenance(Monitor $monitor, CarbonImmutable $start, CarbonImmutable $end): void
{
    DB::table('maintenance_windows')->insert([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'reason' => 'Synthetic maintenance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens no incident during maintenance and opens it once a persistent failure outlasts the window', function (): void {
    $monitor = detectionMonitor(Team::factory()->create());
    // The window covers the first slots, which may precede the reference instant, and ends 25 minutes later.
    $end = CarbonImmutable::now('UTC')->addMinutes(25);
    detectionMaintenance($monitor, CarbonImmutable::now('UTC')->subHour(), $end);
    $slots = [];

    foreach (range(1, 8) as $slot) {
        $slots[] = detectionSlot($monitor, ['eu' => 'down']);
    }

    // The first slot both at or after the window end and at or after the third consecutive failure confirms.
    $confirming = collect($slots)->slice(2)->first(static fn (CarbonImmutable $slot): bool => !$slot->isBefore($end));
    $incidents = detectionIncidents($monitor);

    expect(collect($slots)->filter(static fn (CarbonImmutable $slot): bool => $slot->isBefore($end))->count())->toBeGreaterThanOrEqual(5)
        ->and($incidents)->toHaveCount(1)
        ->and($incidents[0]->confirmed_at->equalTo($confirming))->toBeTrue()
        ->and($incidents[0]->started_at->equalTo($slots[0]))->toBeTrue()
        ->and(detectionProgress($monitor))->toBe(8);
});

it('resolves during maintenance but does not reopen during it', function (): void {
    $monitor = detectionMonitor(Team::factory()->create());

    foreach (['down', 'down', 'down'] as $outcome) {
        detectionSlot($monitor, ['eu' => $outcome]);
    }

    $now = CarbonImmutable::now('UTC');
    detectionMaintenance($monitor, $now, $now->addHours(2));

    foreach (['up', 'down', 'down', 'down'] as $outcome) {
        detectionSlot($monitor, ['eu' => $outcome]);
    }

    [$incident] = detectionIncidents($monitor);

    expect(detectionIncidents($monitor))->toHaveCount(1)
        ->and($incident->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->reopened_count)->toBe(0);
    Event::assertNotDispatched(IncidentReopened::class);
});
