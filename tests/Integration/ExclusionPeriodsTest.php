<?php

declare(strict_types=1);

/** Verify the merged time excluded from SLA for one monitor: pauses, suspensions and maintenance. */

use App\Data\Metrics\TimeInterval;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Metrics\ExclusionPeriods;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }
});

/**
 * @param int $hour Hour after the fixed UTC origin.
 * @return CarbonImmutable UTC instant.
 */
function exclusionHour(int $hour): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC')->addHours($hour);
}

/**
 * Raw inserts stand in for history: the trigger records pauses at the real transaction instant.
 *
 * @param Monitor $monitor Monitor receiving the history.
 * @param string $kind Pause kind.
 * @param int $start Start hour.
 * @param int|null $end End hour; null keeps the period open.
 */
function exclusionPause(Monitor $monitor, string $kind, int $start, ?int $end): void
{
    DB::table('monitor_pause_periods')->insert([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'kind' => $kind,
        'started_at' => exclusionHour($start),
        'ended_at' => $end === null ? null : exclusionHour($end),
    ]);
}

/**
 * @param Monitor $monitor Monitor receiving the window.
 * @param int $start Start hour.
 * @param int $end End hour.
 */
function exclusionMaintenance(Monitor $monitor, int $start, int $end): void
{
    DB::table('maintenance_windows')->insert([
        'team_id' => $monitor->team_id,
        'monitor_id' => $monitor->id,
        'starts_at' => exclusionHour($start),
        'ends_at' => exclusionHour($end),
        'reason' => 'Synthetic maintenance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param Monitor $monitor Monitor to evaluate.
 * @param int $from Window start hour.
 * @param int $to Window end hour.
 * @return list<array{int, int}> Excluded intervals as start and end hours.
 */
function excludedHours(Monitor $monitor, int $from, int $to): array
{
    $intervals = app(TenantContext::class)->run($monitor->team_id, fn (): array => app(ExclusionPeriods::class)
        ->between($monitor, new TimeInterval(exclusionHour($from), exclusionHour($to))));

    return array_map(static fn (TimeInterval $interval): array => [
        (int) exclusionHour(0)->diffInHours($interval->start),
        (int) exclusionHour(0)->diffInHours($interval->end),
    ], $intervals);
}

it('merges pauses, suspensions and maintenance without double counting and clips to the window', function (): void {
    $team = Team::factory()->create();
    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create());

    exclusionPause($monitor, 'voluntary', 1, 4);
    exclusionPause($monitor, 'plan_suspension', 3, 6);
    exclusionMaintenance($monitor, 6, 8);
    exclusionMaintenance($monitor, 10, 12);
    exclusionMaintenance($monitor, 11, 13);
    exclusionPause($monitor, 'voluntary', 20, null);

    expect(excludedHours($monitor, 0, 24))->toBe([[1, 8], [10, 13], [20, 24]])
        ->and(excludedHours($monitor, 2, 11))->toBe([[2, 8], [10, 11]])
        ->and(excludedHours($monitor, 8, 10))->toBe([])
        ->and(excludedHours($monitor, 21, 22))->toBe([[21, 22]]);
});

it('reads only the requested monitor of the active tenant', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create());
    $sibling = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create());
    $foreign = app(TenantContext::class)->run($other->id, fn (): Monitor => Monitor::factory()->create());

    exclusionMaintenance($sibling, 0, 5);
    exclusionMaintenance($foreign, 0, 5);
    exclusionPause($monitor, 'voluntary', 1, 2);

    expect(excludedHours($monitor, 0, 24))->toBe([[1, 2]])
        ->and(app(TenantContext::class)->run($team->id, fn (): array => app(ExclusionPeriods::class)
            ->between($foreign, new TimeInterval(exclusionHour(0), exclusionHour(24)))))->toBe([]);
});
