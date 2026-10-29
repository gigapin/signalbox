<?php

declare(strict_types=1);

/** Verify maintenance window scheduling rules, immutability of the past, authorization and constraints. */

use App\Actions\Incidents\ManageMaintenanceWindows;
use App\Exceptions\MaintenanceWindowRejected;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Incidents\MaintenanceSchedule;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Owning team.
 * @return Monitor Monitor of that team.
 */
function maintenanceMonitor(Team $team): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create());
}

/**
 * @param Team $team Owning team.
 * @return int Number of windows of that team.
 */
function maintenanceCount(Team $team): int
{
    return app(TenantContext::class)->run($team->id, fn (): int => MaintenanceWindow::query()->count());
}

it('schedules a window starting now or later with a trimmed reason', function (): void {
    $team = Team::factory()->create();
    $monitor = maintenanceMonitor($team);
    $actions = app(ManageMaintenanceWindows::class);
    $now = CarbonImmutable::now('UTC');

    $immediate = $actions->schedule($team->owner, $team->id, $monitor->id, null, $now->addHour(), '  Database upgrade  ');
    $planned = $actions->schedule($team->owner, $team->id, $monitor->id, $now->addDay(), $now->addDay()->addHours(2), 'Network change');

    expect($immediate->starts_at->equalTo($now))->toBeTrue()
        ->and($immediate->reason)->toBe('Database upgrade')
        ->and($immediate->created_by)->toBe($team->owner->id)
        ->and($planned->starts_at->equalTo($now->addDay()))->toBeTrue()
        ->and(app(TenantContext::class)->run($team->id, fn (): array => [
            app(MaintenanceSchedule::class)->activeAt($monitor->id, $now),
            app(MaintenanceSchedule::class)->activeAt($monitor->id, $now->addHour()),
            app(MaintenanceSchedule::class)->activeAt($monitor->id, $now->addDay()),
        ]))->toBe([true, false, true]);
});

it('rejects past starts, empty intervals and invalid reasons', function (string $case): void {
    $team = Team::factory()->create();
    $monitor = maintenanceMonitor($team);
    $now = CarbonImmutable::now('UTC');
    [$start, $end, $reason] = match ($case) {
        'past start' => [$now->subSecond(), $now->addHour(), 'Upgrade'],
        'end at start' => [$now->addHour(), $now->addHour(), 'Upgrade'],
        'end before start' => [$now->addHour(), $now->addMinute(), 'Upgrade'],
        'blank reason' => [null, $now->addHour(), "  \t "],
        default => [null, $now->addHour(), str_repeat('x', ManageMaintenanceWindows::REASON_MAX_LENGTH + 1)],
    };

    expect(fn () => app(ManageMaintenanceWindows::class)->schedule($team->owner, $team->id, $monitor->id, $start, $end, $reason))
        ->toThrow(MaintenanceWindowRejected::class)
        ->and(maintenanceCount($team))->toBe(0);
})->with(['past start', 'end at start', 'end before start', 'blank reason', 'long reason']);

it('authorizes managers only and hides other teams', function (string $role, ?string $exception): void {
    $team = Team::factory()->create();
    $monitor = maintenanceMonitor($team);
    $actor = User::factory()->create();
    $team->users()->attach($actor->id, ['role' => $role]);
    $schedule = fn () => app(ManageMaintenanceWindows::class)->schedule($actor, $team->id, $monitor->id, null, CarbonImmutable::now('UTC')->addHour(), 'Upgrade');

    if ($exception !== null) {
        expect($schedule)->toThrow($exception);

        return;
    }

    expect($schedule()->monitor_id)->toBe($monitor->id);
})->with([
    'admin' => ['admin', null],
    'member' => ['member', null],
    'viewer' => ['viewer', AuthorizationException::class],
]);

it('reports foreign monitors and windows as missing', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $monitor = maintenanceMonitor($team);
    $actions = app(ManageMaintenanceWindows::class);
    $window = $actions->schedule($team->owner, $team->id, $monitor->id, null, CarbonImmutable::now('UTC')->addHour(), 'Upgrade');

    expect(fn () => $actions->schedule($other->owner, $other->id, $monitor->id, null, CarbonImmutable::now('UTC')->addHour(), 'Upgrade'))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $actions->cancel($other->owner, $other->id, $window->id))->toThrow(ModelNotFoundException::class)
        ->and(maintenanceCount($other))->toBe(0)
        ->and(maintenanceCount($team))->toBe(1);
});

it('reschedules only windows that have not started', function (): void {
    $team = Team::factory()->create();
    $monitor = maintenanceMonitor($team);
    $actions = app(ManageMaintenanceWindows::class);
    $now = CarbonImmutable::now('UTC');
    $future = $actions->schedule($team->owner, $team->id, $monitor->id, $now->addHour(), $now->addHours(2), 'Upgrade');
    $ongoing = $actions->schedule($team->owner, $team->id, $monitor->id, null, $now->addHours(2), 'Upgrade');

    $moved = $actions->reschedule($team->owner, $team->id, $future->id, $now->addHours(3), $now->addHours(4), 'Moved upgrade');

    expect($moved->starts_at->equalTo($now->addHours(3)))->toBeTrue()
        ->and($moved->reason)->toBe('Moved upgrade')
        ->and(fn () => $actions->reschedule($team->owner, $team->id, $ongoing->id, $now->addHour(), $now->addHours(5), 'Rewrite'))
        ->toThrow(MaintenanceWindowRejected::class);
});

it('deletes future windows, ends ongoing windows now and keeps finished windows', function (): void {
    $team = Team::factory()->create();
    $monitor = maintenanceMonitor($team);
    $actions = app(ManageMaintenanceWindows::class);
    $now = CarbonImmutable::now('UTC');
    $future = $actions->schedule($team->owner, $team->id, $monitor->id, $now->addHour(), $now->addHours(2), 'Future');
    $ongoing = $actions->schedule($team->owner, $team->id, $monitor->id, null, $now->addHours(2), 'Ongoing');
    $finished = $actions->schedule($team->owner, $team->id, $monitor->id, null, $now->addMinutes(10), 'Short');

    CarbonImmutable::setTestNow($now->addMinutes(30));

    expect($actions->cancel($team->owner, $team->id, $future->id))->toBeNull()
        ->and($actions->cancel($team->owner, $team->id, $ongoing->id)?->ends_at->equalTo($now->addMinutes(30)))->toBeTrue()
        ->and(fn () => $actions->cancel($team->owner, $team->id, $finished->id))->toThrow(MaintenanceWindowRejected::class)
        ->and(maintenanceCount($team))->toBe(2);
});

it('enforces the interval and reason in PostgreSQL and cascades with the monitor', function (): void {
    $team = Team::factory()->create();
    $monitor = maintenanceMonitor($team);
    $window = app(ManageMaintenanceWindows::class)->schedule($team->owner, $team->id, $monitor->id, null, CarbonImmutable::now('UTC')->addHour(), 'Upgrade');

    expect(fn () => DB::transaction(fn () => DB::table('maintenance_windows')->where('id', $window->id)->update(['ends_at' => $window->starts_at])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('maintenance_windows')->where('id', $window->id)->update(['reason' => ' '])))
        ->toThrow(QueryException::class);

    app(TenantContext::class)->run($team->id, fn () => $monitor->delete());

    expect(maintenanceCount($team))->toBe(0);
});
