<?php

declare(strict_types=1);

/** Verify that every change of the pause and plan suspension flags records history in PostgreSQL. */

use App\Actions\Monitoring\ManageMonitors;
use App\Enums\Monitoring\PauseKind;
use App\Models\Monitor;
use App\Models\MonitorPausePeriod;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }
});

/**
 * @param Monitor $monitor Monitor to inspect.
 * @return list<array{string, bool}> Periods as kind and whether each is still open, oldest first.
 */
function pausePeriods(Monitor $monitor): array
{
    return app(TenantContext::class)->run($monitor->team_id, fn (): array => MonitorPausePeriod::query()
        ->where('monitor_id', $monitor->id)->orderBy('id')->get()
        ->map(static fn (MonitorPausePeriod $period): array => [$period->kind->value, $period->ended_at === null])->all());
}

/**
 * @param Team $team Owning team.
 * @param array<string, mixed> $attributes Initial attributes.
 * @return Monitor Monitor of that team.
 */
function pauseMonitor(Team $team, array $attributes = []): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create($attributes));
}

it('opens and closes a voluntary period through pause and resume, idempotently', function (): void {
    $team = Team::factory()->create();
    $monitor = pauseMonitor($team);
    $actions = app(ManageMonitors::class);

    expect(pausePeriods($monitor))->toBe([]);

    $actions->pause($team->owner, $team->id, $monitor->id);
    $actions->pause($team->owner, $team->id, $monitor->id);
    expect(pausePeriods($monitor))->toBe([['voluntary', true]]);

    $actions->resume($team->owner, $team->id, $monitor->id);
    $actions->resume($team->owner, $team->id, $monitor->id);
    $actions->pause($team->owner, $team->id, $monitor->id);

    $periods = app(TenantContext::class)->run($team->id, fn () => MonitorPausePeriod::query()->orderBy('id')->get());

    expect(pausePeriods($monitor))->toBe([['voluntary', false], ['voluntary', true]])
        ->and($periods[0]->ended_at?->greaterThanOrEqualTo($periods[0]->started_at))->toBeTrue()
        ->and($periods[0]->team_id)->toBe($team->id);
});

it('records plan suspensions from bulk updates independently of voluntary pauses', function (): void {
    $team = Team::factory()->create();
    $monitor = pauseMonitor($team);

    DB::table('monitors')->where('id', $monitor->id)->update(['is_plan_suspended' => true]);
    DB::table('monitors')->where('id', $monitor->id)->update(['is_paused' => true]);
    DB::table('monitors')->where('id', $monitor->id)->update(['is_plan_suspended' => false, 'name' => 'Renamed']);
    DB::table('monitors')->where('id', $monitor->id)->update(['is_paused' => true]);

    expect(pausePeriods($monitor))->toBe([['plan_suspension', false], ['voluntary', true]]);
});

it('records monitors created already paused or suspended', function (): void {
    $monitor = pauseMonitor(Team::factory()->create(), ['is_paused' => true, 'is_plan_suspended' => true]);

    expect(pausePeriods($monitor))->toBe([['voluntary', true], ['plan_suspension', true]]);
});

it('allows a single open period per kind and rejects reversed periods', function (): void {
    $team = Team::factory()->create();
    $monitor = pauseMonitor($team, ['is_paused' => true]);
    $open = ['team_id' => $team->id, 'monitor_id' => $monitor->id, 'kind' => 'voluntary', 'started_at' => now()];

    expect(fn () => DB::transaction(fn () => DB::table('monitor_pause_periods')->insert($open)))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('monitor_pause_periods')->insert([...$open, 'kind' => 'plan_suspension', 'ended_at' => now()->subMinute()])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('monitor_pause_periods')->insert([...$open, 'kind' => 'holiday', 'ended_at' => now()])))
        ->toThrow(QueryException::class);
});

it('rejects application writes and cascades with the monitor', function (): void {
    $team = Team::factory()->create();
    $monitor = pauseMonitor($team, ['is_paused' => true]);

    app(TenantContext::class)->run($team->id, function () use ($monitor): void {
        $period = MonitorPausePeriod::query()->sole();

        expect($period->kind)->toBe(PauseKind::Voluntary)
            ->and(fn () => $period->forceFill(['ended_at' => now()])->save())->toThrow(LogicException::class);

        $monitor->delete();

        expect(MonitorPausePeriod::query()->count())->toBe(0);
    });
});

it('backfills open periods for monitors paused before the migration', function (): void {
    $team = Team::factory()->create();
    $paused = pauseMonitor($team, ['is_paused' => true]);
    $suspended = pauseMonitor($team, ['is_plan_suspended' => true]);
    $active = pauseMonitor($team);
    $migration = require base_path('database/migrations/2026_10_28_000016_create_monitor_pause_periods.php');

    $migration->down();
    $migration->up();

    expect(pausePeriods($paused))->toBe([['voluntary', true]])
        ->and(pausePeriods($suspended))->toBe([['plan_suspension', true]])
        ->and(pausePeriods($active))->toBe([]);
});
