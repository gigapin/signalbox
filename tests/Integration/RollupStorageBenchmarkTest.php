<?php

declare(strict_types=1);

/**
 * Measure the storage of exact latency frequencies on synthetic days through the production writer.
 * The suite only checks invariants; with RUN_ROLLUP_STORAGE_BENCHMARK=1 it also prints the measurements recorded in METRIC-ROLLUPS.md.
 */

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\HourlyRollup;
use App\Data\Metrics\LatencyDistribution;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Metrics\Rollups\RollupStorageReport;
use App\Services\Metrics\Rollups\RollupStore;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
});

/**
 * Lognormal latencies are the usual shape of response times; the seed makes every run identical.
 *
 * @param int $samples Samples to generate.
 * @param float $sigma Spread of the logarithm: larger values give more distinct milliseconds.
 * @return list<int> Whole milliseconds, limited to the longest configurable timeout.
 */
function storageLatencies(int $samples, float $sigma): array
{
    $latencies = [];

    for ($index = 0; $index < $samples; ++$index) {
        $first = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
        $second = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
        $normal = sqrt(-2 * log($first)) * cos(2 * M_PI * $second);

        $latencies[] = min(10000, (int) round(120 * exp($sigma * $normal)));
    }

    return $latencies;
}

/**
 * @param Monitor $monitor Fixture owner of the rows.
 * @param list<list<int>> $hours Latency samples of each hour of one day.
 * @return array{merge_ms: float, hourly: \App\Data\Metrics\RollupStorageLevel, daily: \App\Data\Metrics\RollupStorageLevel} Measurements of that day, all rolled back.
 */
function storageMeasureDay(Monitor $monitor, array $hours): array
{
    $store = app(RollupStore::class);
    $day = CarbonImmutable::parse('2026-10-02T00:00:00Z');
    $rollups = [];

    foreach ($hours as $index => $samples) {
        $rollups[] = new HourlyRollup(
            $day->addHours($index)->getTimestamp() * 1_000_000,
            count($samples),
            0,
            0,
            new AvailabilityDurations(3_600_000_000, 0, 0, 0),
            LatencyDistribution::fromSamples($samples),
        );
    }

    DB::beginTransaction();

    try {
        $store->writeHours($monitor->team_id, $monitor->id, $rollups);

        $started = hrtime(true);
        $store->rebuildDay($monitor->team_id, $monitor->id, $day);
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        [$hourly, $daily] = app(RollupStorageReport::class)->measure();
    } finally {
        DB::rollBack();
    }

    return ['merge_ms' => $elapsed, 'hourly' => $hourly, 'daily' => $daily];
}

it('measures the frequency maps of each plan cadence within their structural limits', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create());

    // Two regions at the fastest cadence of each plan, with stable and volatile targets, plus the worst possible day.
    $scenarios = [
        'free 300s stable' => ['plan' => 'free', 'perHour' => 24, 'sigma' => 0.25],
        'free 300s volatile' => ['plan' => 'free', 'perHour' => 24, 'sigma' => 1.0],
        'pro 60s stable' => ['plan' => 'pro', 'perHour' => 120, 'sigma' => 0.25],
        'pro 60s volatile' => ['plan' => 'pro', 'perHour' => 120, 'sigma' => 1.0],
        'business 30s stable' => ['plan' => 'business', 'perHour' => 240, 'sigma' => 0.25],
        'business 30s volatile' => ['plan' => 'business', 'perHour' => 240, 'sigma' => 1.0],
        'business 30s all distinct' => ['plan' => 'business', 'perHour' => 240, 'sigma' => null],
    ];
    $lines = [];

    mt_srand(20261030);

    foreach ($scenarios as $name => $scenario) {
        $hours = [];

        foreach (range(0, 23) as $hour) {
            $hours[] = $scenario['sigma'] === null
                ? range($hour * $scenario['perHour'], ($hour + 1) * $scenario['perHour'] - 1)
                : storageLatencies($scenario['perHour'], $scenario['sigma']);
        }

        $measured = storageMeasureDay($monitor, $hours);
        $hourly = $measured['hourly'];
        $daily = $measured['daily'];

        expect($hourly->rows)->toBe(24)
            ->and($daily->rows)->toBe(1)
            ->and($hourly->maximumDistinctValues)->toBeLessThanOrEqual($scenario['perHour'])
            ->and($daily->maximumDistinctValues)->toBeLessThanOrEqual($scenario['perHour'] * 24)
            ->and($hourly->averageFrequencyBytes)->toBeGreaterThan(0.0)
            ->and($daily->averageFrequencyBytes)->toBeGreaterThan(0.0);

        $retainedDayBytes = 24 * $hourly->averageRowBytes + $daily->averageRowBytes;
        $retention = (int) config('plans.'.$scenario['plan'].'.rollup_retention_days');
        $monitors = (int) config('plans.'.$scenario['plan'].'.monitors');
        $monitorBytes = $retainedDayBytes * $retention;

        $lines[] = sprintf(
            '| %s | %.1f | %d | %.0f | %.0f | %d | %.1f | %.1f | %.1f | %.1f |',
            $name,
            $hourly->averageDistinctValues,
            $daily->maximumDistinctValues,
            $hourly->averageRowBytes,
            $daily->averageRowBytes,
            $daily->maximumFrequencyBytes,
            $retainedDayBytes / 1024,
            $monitorBytes / 1024 / 1024,
            $monitorBytes * $monitors / 1024 / 1024 / 1024,
            $measured['merge_ms'],
        );
    }

    mt_srand();

    if (getenv('RUN_ROLLUP_STORAGE_BENCHMARK') === '1') {
        fwrite(STDOUT, "\n| Scenario | Avg distinct/hour | Distinct/day | Hour row B | Day row B | Day map B | KiB/day | MiB/monitor at retention | GiB/full plan | Day merge ms |\n");
        fwrite(STDOUT, implode("\n", $lines)."\n");
    }
});
