<?php

declare(strict_types=1);

/** Drive outcome sequences through the real ledger and check the resulting incident lifecycle as one matrix. */

use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\MonitorRegions;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Models\CheckExecution;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Team;
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
 * @param list<MonitorRegion> $regions Configured simulated regions.
 * @return Monitor HTTP monitor of a new team, due now, threshold three, 300 second cadence.
 */
function lmMonitor(array $regions = [MonitorRegion::Eu]): Monitor
{
    $team = Team::factory()->create();

    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create([
        'regions_json' => new MonitorRegions($regions),
        'next_check_at' => CarbonImmutable::now('UTC')->subSecond(),
    ]));
}

/**
 * Advance the clock to the monitor's next slot and reserve it through production code.
 *
 * @param Monitor $monitor Monitor whose next slot is due.
 * @return array<string, CheckExecution> Pending executions of the new slot keyed by region value.
 */
function lmReserve(Monitor $monitor): array
{
    $tenants = app(TenantContext::class);
    $next = $tenants->run($monitor->team_id, fn (): CarbonImmutable => Monitor::query()->findOrFail($monitor->id)->next_check_at);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->max($next));
    app(ReserveMonitorChecks::class)->run();

    return $tenants->run($monitor->team_id, function () use ($monitor): array {
        $slot = CheckExecution::query()->where('monitor_id', $monitor->id)->max('slot_at');

        return CheckExecution::query()
            ->where('monitor_id', $monitor->id)
            ->where('slot_at', $slot)
            ->where('state', 'pending')
            ->get()
            ->keyBy('region')
            ->all();
    });
}

/**
 * @param CheckExecution $work Pending execution.
 * @return CheckExecution Execution acquired at the current instant.
 */
function lmAcquire(CheckExecution $work): CheckExecution
{
    $acquired = app(ExecutionLedger::class)->acquire($work->team_id, $work->id);
    expect($acquired)->toBeInstanceOf(CheckExecution::class);

    return $acquired;
}

/**
 * @param CheckExecution $acquired Running execution.
 * @param string $outcome `D` observed down, `U` observed up or `I` infrastructure failure.
 */
function lmFinish(CheckExecution $acquired, string $outcome): void
{
    $ledger = app(ExecutionLedger::class);

    if ($outcome === 'I') {
        expect($ledger->finish($acquired, true))->toBeTrue();

        return;
    }

    $down = $outcome === 'D';
    $status = $down ? MonitorStatus::Down : MonitorStatus::Up;
    $context = new CheckContext($acquired->team_id, $acquired->monitor_id, $acquired->id, MonitorRegion::from($acquired->region), $acquired->slot_at);
    $finished = $ledger->finish($acquired, false, new CheckOutcome($context, $status, CarbonImmutable::now('UTC'), 10.0, $down ? 503 : 200, $down ? CheckError::UnexpectedStatus : null));
    expect($finished)->toBeTrue();
}

/**
 * Run one slot with the given outcome per region, in region order.
 *
 * @param Monitor $monitor Monitor whose next slot is due.
 * @param array<string, string> $outcomes Region value to `D`, `U` or `I`.
 * @return CarbonImmutable Executed slot.
 */
function lmSlot(Monitor $monitor, array $outcomes): CarbonImmutable
{
    $works = lmReserve($monitor);
    expect(array_keys($works))->toEqualCanonicalizing(array_keys($outcomes));

    foreach ($outcomes as $region => $outcome) {
        lmFinish(lmAcquire($works[$region]), $outcome);
    }

    return reset($works)->slot_at;
}

/**
 * @param Monitor $monitor Single-region monitor.
 * @param string $sequence One character per slot: `D`, `U` or `I`.
 */
function lmRun(Monitor $monitor, string $sequence): void
{
    foreach (str_split($sequence) as $outcome) {
        lmSlot($monitor, ['eu' => $outcome]);
    }
}

/**
 * @param Monitor $monitor Monitor to inspect.
 * @return array{incidents: int, status: string|null, reopened: int, progress: int} Count, latest status, its reopenings and joint progress.
 */
function lmState(Monitor $monitor): array
{
    $incidents = app(TenantContext::class)->run($monitor->team_id, fn () => Incident::query()->where('monitor_id', $monitor->id)->orderBy('id')->get());
    $latest = $incidents->last();

    return [
        'incidents' => $incidents->count(),
        'status' => $latest?->status->value,
        'reopened' => $latest === null ? 0 : $latest->reopened_count,
        'progress' => (int) DB::table('monitors')->where('id', $monitor->id)->value('consecutive_failures'),
    ];
}

it('applies threshold three, recovery, reopening and flapping rules to single-region sequences', function (string $sequence, int $incidents, ?string $status, int $reopened, int $progress): void {
    $monitor = lmMonitor();

    lmRun($monitor, $sequence);

    expect(lmState($monitor))->toBe(['incidents' => $incidents, 'status' => $status, 'reopened' => $reopened, 'progress' => $progress]);
})->with([
    'first failure' => ['D', 0, null, 0, 1],
    'second failure' => ['DD', 0, null, 0, 2],
    'third failure confirms' => ['DDD', 1, 'investigating', 0, 3],
    'persistent failure opens once' => ['DDDDDD', 1, 'investigating', 0, 6],
    'success interrupts' => ['DDUDD', 0, null, 0, 2],
    'infrastructure interrupts' => ['DDIDD', 0, null, 0, 2],
    'infrastructure never confirms' => ['IIIIII', 0, null, 0, 0],
    'recovery resolves' => ['DDDU', 1, 'resolved', 0, 0],
    'infrastructure does not resolve' => ['DDDI', 1, 'investigating', 0, 0],
    'reconfirmed at the window boundary reopens' => ['DDDUDDD', 1, 'investigating', 1, 3],
    'reconfirmed beyond the window opens anew' => ['DDDUUDDD', 2, 'investigating', 0, 3],
    'alternating flapping never opens' => ['DUDUDUDUDU', 0, null, 0, 0],
    'two-failure flapping never opens' => ['DDUDDUDDUDDU', 0, null, 0, 0],
    'flapping around an incident reopens it' => ['DDDUDDDUDDDU', 1, 'resolved', 2, 0],
]);

it('records one system entry per lifecycle change while an incident flaps', function (): void {
    $monitor = lmMonitor();

    lmRun($monitor, 'DDDUDDDUDDDU');

    $incident = app(TenantContext::class)->run($monitor->team_id, fn (): Incident => Incident::query()->where('monitor_id', $monitor->id)->sole());
    $messages = app(TenantContext::class)->run($monitor->team_id, fn (): array => $incident->updates()->pluck('message')->all());

    expect($messages)->toHaveCount(6)
        ->and(array_count_values(array_map(static fn (string $message): string => strtok($message, ' '), $messages)))
        ->toBe(['Failure' => 1, 'Recovery' => 3, 'The' => 2]);
    Event::assertDispatchedTimes(IncidentOpened::class, 1);
    Event::assertDispatchedTimes(IncidentReopened::class, 2);
    Event::assertDispatchedTimes(IncidentResolved::class, 3);
});

it('requires both regions to agree before opening or resolving', function (array $slots, int $incidents, ?string $status, int $progress): void {
    $monitor = lmMonitor([MonitorRegion::Eu, MonitorRegion::Us]);

    foreach ($slots as [$eu, $us]) {
        lmSlot($monitor, ['eu' => $eu, 'us' => $us]);
    }

    expect(lmState($monitor))->toBe(['incidents' => $incidents, 'status' => $status, 'reopened' => 0, 'progress' => $progress]);
})->with([
    'one region always down' => [[['D', 'U'], ['D', 'U'], ['D', 'U'], ['D', 'U'], ['D', 'U']], 0, null, 0],
    'regions failing in turn' => [[['D', 'U'], ['U', 'D'], ['D', 'U'], ['U', 'D'], ['D', 'U']], 0, null, 0],
    'disagreement breaks one sequence' => [[['D', 'D'], ['D', 'D'], ['D', 'U'], ['D', 'D'], ['D', 'D']], 0, null, 2],
    'infrastructure in one region is inconclusive' => [[['D', 'I'], ['D', 'I'], ['D', 'I'], ['D', 'I']], 0, null, 0],
    'both regions confirm' => [[['D', 'D'], ['D', 'D'], ['D', 'D']], 1, 'investigating', 3],
    'one region recovering keeps it open' => [[['D', 'D'], ['D', 'D'], ['D', 'D'], ['U', 'D'], ['U', 'D'], ['U', 'D'], ['U', 'D']], 1, 'investigating', 0],
    'joint recovery resolves' => [[['D', 'D'], ['D', 'D'], ['D', 'D'], ['U', 'D'], ['U', 'U']], 1, 'resolved', 0],
]);

it('opens nothing for a failure contained in maintenance and keeps an earlier incident single', function (): void {
    $contained = lmMonitor();
    $planned = lmMonitor();
    $start = CarbonImmutable::now('UTC');
    $end = $start->addHours(2);

    foreach ([$contained, $planned] as $monitor) {
        app(TenantContext::class)->run($monitor->team_id, function () use ($monitor, $start, $end): void {
            (new MaintenanceWindow())->forceFill(['monitor_id' => $monitor->id, 'starts_at' => $start->subMinutes(10), 'ends_at' => $end, 'reason' => 'Matrix maintenance'])->save();
        });
    }

    lmRun($contained, 'DDDDDU');
    expect(lmState($contained))->toBe(['incidents' => 0, 'status' => null, 'reopened' => 0, 'progress' => 0]);

    // An incident opened before the window stays the only one and still resolves inside it.
    app(TenantContext::class)->run($planned->team_id, fn () => MaintenanceWindow::query()->where('monitor_id', $planned->id)->delete());
    lmRun($planned, 'DDD');
    app(TenantContext::class)->run($planned->team_id, function () use ($planned): void {
        $now = CarbonImmutable::now('UTC');
        (new MaintenanceWindow())->forceFill(['monitor_id' => $planned->id, 'starts_at' => $now, 'ends_at' => $now->addHours(2), 'reason' => 'Matrix maintenance'])->save();
    });
    lmRun($planned, 'DDDU');
    expect(lmState($planned))->toBe(['incidents' => 1, 'status' => 'resolved', 'reopened' => 0, 'progress' => 0]);
});

it('ignores a late result of an older slot that completes after a newer one', function (): void {
    $monitor = lmMonitor();
    lmRun($monitor, 'DD');

    $late = lmReserve($monitor)['eu'];
    CarbonImmutable::setTestNow($late->slot_at->addSeconds(290));
    $running = lmAcquire($late);

    $newer = lmSlot($monitor, ['eu' => 'D']);
    CarbonImmutable::setTestNow($newer->addSeconds(20));
    lmFinish($running, 'D');

    // The newer slot did not follow a processed slot, so it started a sequence; the late slot is history.
    expect(lmState($monitor))->toBe(['incidents' => 0, 'status' => null, 'reopened' => 0, 'progress' => 1])
        ->and(DB::table('check_results')->where('monitor_id', $monitor->id)->count())->toBe(4);

    lmRun($monitor, 'DD');
    $incident = app(TenantContext::class)->run($monitor->team_id, fn (): Incident => Incident::query()->where('monitor_id', $monitor->id)->sole());
    expect($incident->started_at->equalTo($newer))->toBeTrue();
});

it('does not resolve an open incident from a late success of an older slot', function (): void {
    $monitor = lmMonitor();
    lmRun($monitor, 'DDD');

    $late = lmReserve($monitor)['eu'];
    CarbonImmutable::setTestNow($late->slot_at->addSeconds(290));
    $running = lmAcquire($late);

    $newer = lmSlot($monitor, ['eu' => 'D']);
    CarbonImmutable::setTestNow($newer->addSeconds(20));
    lmFinish($running, 'U');

    expect(lmState($monitor)['status'])->toBe('investigating');

    lmRun($monitor, 'U');
    expect(lmState($monitor))->toBe(['incidents' => 1, 'status' => 'resolved', 'reopened' => 0, 'progress' => 0]);
});
