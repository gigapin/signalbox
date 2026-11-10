<?php

declare(strict_types=1);

/** Verify private metric read contracts, exact observations, retention, redaction and tenant boundaries. */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\EscalationPolicy;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ReadModels\MonitorContext;
use App\Services\Monitoring\ReadModels\MonitorMetrics;
use App\Services\Monitoring\Results\ResultPartitions;
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
    app(ResultPartitions::class)->ensure(now()->toImmutable()->subDays(32));
    app(ResultPartitions::class)->ensure(now()->toImmutable());
    $this->team = Team::factory()->business()->create();
    $this->foreign = Team::factory()->business()->create();
    $this->monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create(['created_at' => now()->subDays(32)]));
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '']);
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Monitor $monitor Synthetic owner of a real ledger/result pair.
 * @param int|null $latency Observed milliseconds, including zero.
 * @param string $region Simulated execution region.
 * @param string $slot Half-open partition/slot instant.
 * @param array<string, mixed> $extra Safe result overrides for boundary/provenance cases.
 */
function metricObservation(Monitor $monitor, ?int $latency, string $region = 'eu', string $slot = '2026-11-10T11:00:00Z', array $extra = []): void
{
    $at = CarbonImmutable::parse($slot);
    $id = DB::table('check_executions')->insertGetId([
        'team_id' => $monitor->team_id, 'monitor_id' => $monitor->id, 'region' => $region,
        'slot_at' => $at, 'expires_at' => $at->addMinutes(5), 'monitor_revision' => $monitor->execution_revision,
        'state' => 'completed', 'available_at' => $at, 'finished_at' => $at->addSecond(),
    ]);
    DB::table('check_results')->insert([
        'team_id' => $monitor->team_id, 'monitor_id' => $monitor->id, 'execution_id' => $id, 'region' => $region,
        'slot_at' => $at, 'monitor_revision' => $monitor->execution_revision, 'kind' => 'observed',
        'status' => 'up', 'response_time_ms' => $latency, 'checked_at' => $at->addSecond(), 'recorded_at' => $at->addSecond(),
        ...$extra,
    ]);
}

/**
 * @param Team $team Authorized route team, established as the active context.
 * @param Monitor $monitor Synthetic route-bound monitor.
 * @param string $period One of the supported rolling periods.
 * @param string $region All, EU or US observations.
 * @return array<string, mixed> Real composed metrics inside the authorized tenant context.
 */
function projectedMetrics(Team $team, Monitor $monitor, string $period = '24h', string $region = 'all'): array
{
    return app(TenantContext::class)->run($team->id, fn (): array => app(MonitorMetrics::class)->read($monitor, $team, $period, $region));
}

it('projects empty time as unknown and never invents observed uptime or measured zero', function (): void {
    $view = projectedMetrics($this->team, $this->monitor);
    expect($view['recent'])->toHaveCount(24)
        ->and($view['periods']['24h']['observedPercent'])->toBeNull()
        ->and($view['periods']['24h']['coveragePercent'])->toBe(0.0)
        ->and($view['periods']['24h']['durations']['unknownSeconds'])->toEqual(86400)
        ->and($view['latency']['series'][0]['eu'])->toBe(['meanMs' => null, 'p50Ms' => null, 'p95Ms' => null, 'samples' => 0]);
});

it('computes exact percentiles across uneven samples and keeps regions and missing values distinct', function (): void {
    metricObservation($this->monitor, 0);
    metricObservation($this->monitor, 100, slot: '2026-11-10T11:05:00Z');
    metricObservation($this->monitor, 100, slot: '2026-11-10T11:10:00Z');
    metricObservation($this->monitor, null, slot: '2026-11-10T11:15:00Z');
    metricObservation($this->monitor, 900, 'us');
    $point = projectedMetrics($this->team, $this->monitor)['latency']['series'][23];
    expect($point['eu'])->toBe(['meanMs' => 66.67, 'p50Ms' => 100, 'p95Ms' => 100, 'samples' => 3])
        ->and($point['us']['meanMs'])->toBe(900.0);
    $filtered = projectedMetrics($this->team, $this->monitor, '7d', 'eu');
    expect($filtered['latency']['series'])->toHaveCount(7)
        ->and($filtered['latency']['series'][6]['us']['samples'])->toBe(0)
        ->and($filtered['latency']['series'][6]['eu']['samples'])->toBe(3);
});

it('excludes foreign monitors future recordings and the exclusive range end', function (): void {
    $other = app(TenantContext::class)->run($this->foreign->id, fn (): Monitor => Monitor::factory()->create());
    metricObservation($other, 999);
    metricObservation($this->monitor, 999, slot: '2026-11-10T12:00:00Z');
    metricObservation($this->monitor, 999, slot: '2026-11-10T11:05:00Z', extra: ['recorded_at' => now()->addMinute()]);
    metricObservation($this->monitor, 10);
    $view = projectedMetrics($this->team, $this->monitor);
    expect($view['latency']['series'][23]['eu']['samples'])->toBe(1)
        ->and($view['latency']['series'][23]['eu']['meanMs'])->toBe(10.0);
});

it('returns bounded thirty-day statistics and marks plan-restricted history unavailable', function (): void {
    metricObservation($this->monitor, 10, slot: '2026-10-11T12:00:00Z');
    $view = projectedMetrics($this->team, $this->monitor, '30d');
    expect($view['latency']['series'])->toHaveCount(30)
        ->and($view['latency']['series'][0]['eu']['samples'])->toBe(1);
    config(['plans.business.rollup_retention_days' => 7]);
    $view = projectedMetrics($this->team, $this->monitor, '30d');
    expect($view['periods']['30d']['available'])->toBeFalse()
        ->and($view['latency']['available'])->toBeFalse();
});

it('honors retained data and the creation boundary without reading physically present expired raw results', function (): void {
    app(ResultPartitions::class)->ensure(now()->toImmutable()->subDays(35));
    metricObservation($this->monitor, 999, slot: '2026-10-10T12:00:00Z');
    $view = projectedMetrics($this->team, $this->monitor, '30d');
    expect(array_sum(array_column(array_column($view['latency']['series'], 'eu'), 'samples')))->toBe(0);
    app(TenantContext::class)->run($this->team->id, function (): void {
        $this->monitor->forceFill(['created_at' => now()->subMinutes(30)])->save();
    });
    expect(projectedMetrics($this->team, $this->monitor)['periods']['24h']['durations']['unknownSeconds'])->toEqual(1800);
});

it('accepts bounded period region controls and rejects malformed arrays without redirect or input reflection', function (mixed $period, mixed $region): void {
    $query = http_build_query(['period' => $period, 'region' => $region]);
    $response = $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/monitors/'.$this->monitor->id.'?'.$query);
    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('props.metrics.selection', ['period' => '24h', 'region' => 'all'])
        ->assertJsonPath('props.rejectedMetricFilters', ['period', 'region']);
})->with([['31d', 'moon'], [['30d'], ['eu']], ['<script>', 'EU']]);

it('allows a viewer to read metric controls and rejects a foreign route monitor or non-member', function (): void {
    $viewer = User::factory()->create();
    Membership::factory()->create(['team_id' => $this->team->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
    $url = '/teams/'.$this->team->id.'/monitors/'.$this->monitor->id;
    $this->actingAs($viewer)->get($url.'?period=7d&region=us')->assertOk()
        ->assertJsonPath('props.metrics.selection', ['period' => '7d', 'region' => 'us'])
        ->assertJsonPath('props.can.update', false);
    $this->get('/teams/'.$this->foreign->id.'/monitors/'.$this->monitor->id)->assertNotFound();
    $this->actingAs($this->foreign->owner)->get($url)->assertNotFound();
});

it('limits destination projection to a redacted URL without decrypting other settings', function (): void {
    app(TenantContext::class)->run($this->team->id, function (): void {
        $this->monitor->forceFill(['url' => 'https://user:password@example.test/health?token=topsecret#private', 'headers_json' => ['authorization' => 'secret'], 'body' => 'private-body'])->save();
    });
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/monitors')->assertOk()
        ->assertJsonPath('props.monitors.data.0.destination', 'https://example.test/health?token=[REDACTED]')
        ->assertDontSee('topsecret', false)->assertDontSee('private-body', false)->assertDontSee('authorization', false);
});

it('distinguishes cached expiry from a retained current-revision certificate observation', function (): void {
    $tls = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->tls()->create(['ssl_expires_at' => now()->addDays(10)]));
    $context = app(TenantContext::class);
    $read = fn (): array => $context->run($this->team->id, fn (): array => app(MonitorContext::class)->certificate($tls));
    expect($read()['state'])->toBe('stale')->and($read()['observedAt'])->toBeNull();
    metricObservation($tls, 10, slot: '2026-11-10T11:59:00Z', extra: ['tls_expires_at' => $tls->ssl_expires_at]);
    expect($read()['state'])->toBe('current')->and($read()['observedAt'])->toBe('2026-11-10T11:59:01.000000Z');
    $this->travelTo(now()->addHour());
    expect($read()['state'])->toBe('stale');
    expect($context->run($this->team->id, fn (): array => app(MonitorContext::class)->certificate($this->monitor))['state'])->toBe('not_applicable');
});

it('resolves default override and none into safe channel flags without configuration', function (): void {
    app(TenantContext::class)->run($this->team->id, function (): void {
        $reader = app(MonitorContext::class);
        expect($reader->notifications($this->monitor))->toBeNull();
        $channel = NotificationChannel::factory()->create();
        $default = EscalationPolicy::factory()->create(['is_default' => true, 'steps_json' => [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]]);
        $view = $reader->notifications($this->monitor);
        expect($view['source'])->toBe('default')->and($view['steps'][0]['channels'][0]['id'])->toBe($channel->id)
            ->and($view['steps'][0]['channels'][0])->not->toHaveKey('config_json');
        $override = EscalationPolicy::factory()->create(['steps_json' => [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]]);
        $this->monitor->forceFill(['escalation_policy_id' => $override->id])->save();
        expect($reader->notifications($this->monitor)['source'])->toBe('override')
            ->and($reader->notifications($this->monitor)['id'])->not->toBe($default->id);
    });
});

it('fails closed when a direct consumer mixes a monitor or active context with another team', function (): void {
    app(TenantContext::class)->run($this->foreign->id, function (): void {
        expect(fn () => app(MonitorMetrics::class)->read($this->monitor, $this->team, '24h', 'all'))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });
    expect(fn () => projectedMetrics($this->foreign, $this->monitor))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('uses authoritative rollup durations for extended periods without averaging percentages', function (): void {
    app(TenantContext::class)->run($this->team->id, function (): void {
        $hours = [];
        $start = CarbonImmutable::now()->subDays(30);
        for ($index = 0; $index < 720; ++$index) {
            $hours[] = new \App\Data\Metrics\HourlyRollup(
                $start->addHours($index)->getTimestamp() * 1_000_000,
                0,
                0,
                0,
                new \App\Data\Metrics\AvailabilityDurations(1_800_000_000, 900_000_000, 0, 900_000_000),
                new \App\Data\Metrics\LatencyDistribution(),
            );
        }
        app(\App\Services\Metrics\Rollups\RollupStore::class)->writeHours($this->team->id, $this->monitor->id, $hours);
        $view = app(MonitorMetrics::class)->read($this->monitor, $this->team, '30d', 'all');
        expect($view['periods']['30d']['observedPercent'])->toBe(66.66)
            ->and($view['periods']['30d']['coveragePercent'])->toBe(100.0)
            ->and($view['periods']['30d']['slaPercent'])->toBe(66.66)
            ->and($view['periods']['30d']['durations']['excludedSeconds'])->toEqual(648000);
    });
});

it('preserves maintenance and fractional boundaries in the recent duration bars', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10T12:00:00.500000Z'));
    app(TenantContext::class)->run($this->team->id, function (): void {
        DB::table('maintenance_windows')->insert([
            'team_id' => $this->team->id,
            'reason' => 'Synthetic fractional exclusion',
            'monitor_id' => $this->monitor->id,
            'starts_at' => '2026-11-10T11:00:00Z',
            'ends_at' => '2026-11-10T11:10:00Z',
        ]);
    });
    $view = projectedMetrics($this->team, $this->monitor);
    expect($view['recent'][23]['durations']['excludedSeconds'])->toBe(599.5)
        ->and($view['recent'][23]['durations']['unknownSeconds'])->toBe(3000.5)
        ->and($view['recent'][23]['slaPercent'])->toBeNull();
});

it('does not execute metric queries on a legacy partial read that omits the new props', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/monitors/'.$this->monitor->id, [
        'X-Inertia-Partial-Component' => 'Monitors/Show',
        'X-Inertia-Partial-Data' => 'monitor,team',
    ])->assertOk()->assertJsonMissingPath('props.metrics')->assertJsonMissingPath('props.monitorContext');
    $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
    DB::disableQueryLog();
    expect($sql)->not->toContain('percentile_disc');
});

it('rejects obsolete certificate revision provenance and does not decrypt channel credentials', function (): void {
    $tls = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->tls()->create(['ssl_expires_at' => now()->addDays(10)]));
    metricObservation($tls, 10, slot: '2026-11-10T11:59:00Z', extra: ['tls_expires_at' => $tls->ssl_expires_at]);
    app(TenantContext::class)->run($this->team->id, function () use ($tls): void {
        $tls->forceFill(['execution_revision' => $tls->execution_revision + 1])->save();
        expect(app(MonitorContext::class)->certificate($tls)['observedAt'])->toBeNull();
        $channel = NotificationChannel::factory()->create();
        EscalationPolicy::factory()->create(['is_default' => true, 'steps_json' => [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]]);
        // Deliberately unreadable ciphertext proves the projection never hydrates channel configuration.
        DB::table('notification_channels')->where('id', $channel->id)->update(['config_json' => 'invalid-ciphertext']);
        expect(app(MonitorContext::class)->notifications($this->monitor)['steps'][0]['channels'])->toHaveCount(1);
    });
});

it('computes whole-window statistics from observations rather than averaging bucket means or percentiles', function (): void {
    metricObservation($this->monitor, 0, slot: '2026-11-10T10:00:00Z');
    metricObservation($this->monitor, 100, slot: '2026-11-10T11:00:00Z');
    metricObservation($this->monitor, 100, slot: '2026-11-10T11:05:00Z');
    $view = projectedMetrics($this->team, $this->monitor);
    expect($view['latency']['summary']['eu'])->toBe(['meanMs' => 66.67, 'p50Ms' => 100, 'p95Ms' => 100, 'samples' => 3]);
});

it('serves the detail metric cards through the existing authorized partial recovery protocol', function (): void {
    metricObservation($this->monitor, 10);
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/monitors/'.$this->monitor->id, [
        'X-Inertia-Partial-Component' => 'Monitors/Show',
        'X-Inertia-Partial-Data' => 'monitor,recentIncidents,maintenance,escalation,can,metrics,team,auth,realtime',
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('props.monitor.id', $this->monitor->id)
        ->assertJsonPath('props.team.id', $this->team->id)
        ->assertJsonCount(24, 'props.metrics.recent')
        ->assertJsonPath('props.metrics.latency.summary.eu.samples', 1)
        ->assertJsonPath('props.metrics.periods.24h.available', true)
        ->assertJsonMissingPath('props.monitorContext');
});

it('projects the selected rolling window for both regional latency and agreed uptime', function (string $period, int $buckets): void {
    metricObservation($this->monitor, 0);
    $view = projectedMetrics($this->team, $this->monitor, $period, 'eu');
    expect($view['supportedPeriods'])->toBe(['24h', '7d', '30d'])
        ->and($view['uptime']['available'])->toBeTrue()
        ->and($view['uptime']['series'])->toHaveCount($buckets)
        ->and($view['latency']['series'])->toHaveCount($buckets)
        ->and($view['uptime']['series'][0]['startsAt'])->toBe($view['latency']['series'][0]['startsAt'])
        ->and($view['uptime']['series'][$buckets - 1]['endsAt'])->toBe($view['latency']['series'][$buckets - 1]['endsAt']);
    expect(projectedMetrics($this->team, $this->monitor, $period, 'us')['uptime'])->toBe($view['uptime']);
})->with([['24h', 24], ['7d', 7], ['30d', 30]]);

it('splits extended availability with one shared timeline and no query per daily bucket', function (): void {
    metricObservation($this->monitor, 10);
    app(TenantContext::class)->run($this->team->id, function (): void {
        $now = CarbonImmutable::now();
        $availability = app(\App\Services\Metrics\MonitorAvailability::class);
        $queries = [];
        foreach ([7, 30] as $days) {
            $window = new \App\Data\Metrics\TimeInterval($now->subDays($days), $now);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $reports = $availability->daily($this->monitor, $window);
            $queries[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            expect($reports)->toHaveCount($days);
            expect(\App\Data\Metrics\AvailabilityReport::combined($reports)->durations)
                ->toEqual($availability->between($this->monitor, $window)->durations);
        }
        expect($queries[0])->toBe($queries[1])->toBeLessThan(12);
        expect(fn () => $availability->daily($this->monitor, new \App\Data\Metrics\TimeInterval($now->subDays(31), $now)))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('restricts chart options to readable monitored life and current plan history', function (): void {
    config(['plans.business.rollup_retention_days' => 7]);
    $view = projectedMetrics($this->team, $this->monitor, '30d');
    expect($view['supportedPeriods'])->toBe(['24h', '7d'])
        ->and($view['uptime']['available'])->toBeFalse()
        ->and($view['uptime']['series'])->toBeEmpty();
    app(TenantContext::class)->run($this->team->id, function (): void {
        $this->monitor->forceFill(['created_at' => now()->subHour()])->save();
    });
    $view = projectedMetrics($this->team, $this->monitor, '30d');
    expect($view['supportedPeriods'])->toBe(['24h', '7d', '30d'])
        ->and($view['uptime']['series'][0]['durations']['upSeconds'])->toEqual(0)
        ->and($view['uptime']['series'][0]['observedPercent'])->toBeNull();
});
