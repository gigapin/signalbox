<?php

declare(strict_types=1);

/** Measure private read query counts, projected rows and request time on disposable, fixed fixtures. */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-10T12:00:00Z'));
    // Keep fixed-clock reads reproducible when migrations start from a later real calendar month.
    app(\App\Services\Monitoring\Results\ResultPartitions::class)->ensure(CarbonImmutable::now());
});

afterEach(function (): void {
    $this->travelBack();
});

it('keeps private read queries bounded as the monitor list grows', function (): void {
    $team = Team::factory()->business()->create();
    $context = app(TenantContext::class);
    $monitor = $context->run($team->id, fn (): Monitor => Monitor::factory()->create(['created_at' => now()->subDays(31)]));
    $this->actingAs($team->owner);
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    $counts = [];

    foreach ([1, 40] as $size) {
        if ($size === 40) {
            $context->run($team->id, fn () => Monitor::factory()->count(39)->create(['created_at' => now()->subDays(31)]));
        }
        foreach (['dashboard', 'monitors', 'monitors/'.$monitor->id] as $path) {
            // Warm route/container paths before collecting three measurements; fixture writes stay excluded.
            $this->get('/teams/'.$team->id.'/'.$path, $headers)->assertOk();
            $samples = [];
            for ($run = 0; $run < 3; ++$run) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $started = hrtime(true);
                $response = $this->get('/teams/'.$team->id.'/'.$path, $headers)->assertOk();
                $samples[] = (hrtime(true) - $started) / 1_000_000;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
            }
            sort($samples);
            $props = $response->json('props');
            $rows = count($props['monitors']['data'] ?? $props['dashboard']['monitors']['data'] ?? [$props['monitor'] ?? []]);
            $counts[$path][$size] = count($queries);
            if (getenv('RUN_PROJECTION_BENCHMARK') === '1') {
                fwrite(STDOUT, sprintf("\nprojection %s monitors=%d queries=%d projectedMonitors=%d medianMs=%.2f sqlMs=%.2f\n", $path, $size, count($queries), $rows, $samples[1], array_sum(array_column($queries, 'time'))));
            }
            expect($rows)->toBeLessThanOrEqual(20);
        }
    }
    foreach ($counts as $sizes) {
        expect($sizes[40])->toBe($sizes[1]);
    }
});

it('measures a full thirty-day observed ledger and SQL aggregate output', function (): void {
    $team = Team::factory()->business()->create();
    $context = app(TenantContext::class);
    $monitor = $context->run($team->id, fn (): Monitor => Monitor::factory()->create(['created_at' => now()->subDays(32)]));
    app(\App\Services\Monitoring\Results\ResultPartitions::class)->ensure(CarbonImmutable::now()->subDays(32));
    DB::insert(<<<'SQL'
        INSERT INTO check_executions (team_id, monitor_id, region, slot_at, expires_at, monitor_revision, state, available_at, finished_at)
        SELECT ?, ?, region, slot, slot + interval '1 hour', 1, 'completed', slot, slot + interval '1 second'
        FROM generate_series('2026-10-11T12:00:00Z'::timestamptz, '2026-11-10T11:00:00Z'::timestamptz, interval '1 hour') slot
        CROSS JOIN (VALUES ('eu'), ('us')) regions(region)
        SQL, [$team->id, $monitor->id]);
    DB::insert(<<<'SQL'
        INSERT INTO check_results (team_id, monitor_id, execution_id, region, slot_at, monitor_revision, kind, status, response_time_ms, checked_at, recorded_at)
        SELECT team_id, monitor_id, id, region, slot_at, monitor_revision, 'observed', 'up',
            CASE WHEN region = 'eu' THEN 10 ELSE 100 END, slot_at + interval '1 second', slot_at + interval '1 second'
        FROM check_executions WHERE team_id = ? AND monitor_id = ?
        SQL, [$team->id, $monitor->id]);
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    foreach (['raw-fallback', 'stored-rollups'] as $storage) {
        if ($storage === 'stored-rollups') {
            $context->run($team->id, function () use ($monitor, $team): void {
                $start = CarbonImmutable::now()->subDays(30);
                $end = CarbonImmutable::now();
                $records = app(\App\Services\Metrics\Rollups\RollupSource::class)->slots($team->id, $monitor->id, $start, $end);
                $hours = app(\App\Services\Metrics\Rollups\HourlyRollupCalculator::class)->calculate($records, (int) $start->format('Uu'), (int) $end->format('Uu'), (int) $monitor->created_at->format('Uu'), []);
                app(\App\Services\Metrics\Rollups\RollupStore::class)->writeHours($team->id, $monitor->id, $hours);
            });
        }
        $url = '/teams/'.$team->id.'/monitors/'.$monitor->id.'?period=30d';
        $this->actingAs($team->owner)->get($url, $headers)->assertOk();
        $times = [];
        for ($run = 0; $run < 3; ++$run) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            $response = $this->get($url, $headers)->assertOk();
            $times[] = (hrtime(true) - $started) / 1_000_000;
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        sort($times);
        $points = $response->json('props.metrics.latency.series');
        expect($points)->toHaveCount(30)
            ->and(array_sum(array_column(array_column($points, 'eu'), 'samples')))->toBe(720)
            ->and($points[0]['eu']['p95Ms'])->toBe(10);
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'percentile_disc')) {
                $plan = DB::select('EXPLAIN (ANALYZE, FORMAT JSON) '.$query['query'], $query['bindings']);
                $decoded = json_decode($plan[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
                expect($decoded['Plan']['Actual Rows'])->toBe(62);
                if (getenv('RUN_PROJECTION_BENCHMARK') === '1') {
                    fwrite(STDOUT, sprintf("\nseeded storage=%s rawResults=1440 period=30d queries=%d returnedRegionalRows=62 medianMs=%.2f sqlMs=%.2f aggregateExecutionMs=%.2f\n", $storage, count($queries), $times[1], array_sum(array_column($queries, 'time')), $decoded['Execution Time']));
                }
            }
        }
    }
});
