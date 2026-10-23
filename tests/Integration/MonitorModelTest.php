<?php

declare(strict_types=1);

/** Verify real monitor persistence, encryption, structural constraints and tenant boundaries on PostgreSQL. */

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\MonitorRegions;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Exceptions\MissingTenantContext;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\MonitorConfigurationMapper;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MonitorConfigurationFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }
});

it('round trips every complete typed monitor configuration without initializing observations', function (MonitorConfiguration $configuration): void {
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, function () use ($team, $configuration): void {
        $monitor = Monitor::factory()->make();
        $monitor->applyConfiguration($configuration);
        expect($monitor->exists)->toBeFalse();
        $monitor->save();
        $fresh = $monitor->fresh();
        expect($fresh->configuration())->toEqual($configuration)
            ->and($fresh->team_id)->toBe($team->id)
            ->and($fresh->regions_json->toValues())->toBe(['eu'])
            ->and($fresh->confirmation_threshold)->toBe(3)
            ->and($fresh->is_paused)->toBeFalse()
            ->and($fresh->is_plan_suspended)->toBeFalse()
            ->and($fresh->last_status)->toBe(MonitorStatus::Unknown)
            ->and($fresh->consecutive_failures)->toBe(0)
            ->and($fresh->last_checked_at)->toBeNull()
            ->and($fresh->next_check_at)->toBeNull()
            ->and($fresh->ssl_expires_at)->toBeNull()
            ->and($fresh->toArray()['regions_json'])->toBe(['eu']);
    });
})->with(MonitorConfigurationFixture::cases());

it('stores HTTP destinations headers and bodies encrypted and hides all configuration by default', function (): void {
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, function (): void {
        $configuration = MonitorConfigurationFixture::cases()['http'][0];
        $monitor = Monitor::factory()->make();
        $monitor->applyConfiguration($configuration);
        $monitor->save();
        $raw = DB::table('monitors')->where('id', $monitor->id)->first();
        expect($raw->url)->not->toContain('synthetic-url-secret')
            ->and($raw->headers_json)->not->toContain('synthetic-header-secret')
            ->and($raw->body)->not->toContain('synthetic-body-secret')
            ->and($monitor->fresh()->configuration())->toEqual($configuration);

        foreach (MonitorConfigurationMapper::COLUMNS as $column) {
            expect($monitor->toArray())->not->toHaveKey($column);
        }

        expect($monitor->toJson())->not->toContain('synthetic-url-secret', 'synthetic-header-secret', 'synthetic-body-secret');
    });
});

it('clears irrelevant configuration columns for every replacement type without saving implicitly', function (MonitorConfiguration $replacement): void {
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, function () use ($replacement): void {
        $monitor = Monitor::factory()->keyword()->create();
        $original = $monitor->configuration();
        $monitor->applyConfiguration($replacement);
        expect($monitor->fresh()->configuration())->toEqual($original);
        $monitor->save();
        $fresh = $monitor->fresh();
        expect($fresh->configuration())->toEqual($replacement);
        $expected = MonitorConfigurationMapper::attributes($replacement);

        foreach (MonitorConfigurationMapper::COLUMNS as $column) {
            if ($expected[$column] === null) {
                expect($fresh->getRawOriginal($column))->toBeNull();
            }
        }
    });
})->with(MonitorConfigurationFixture::cases());

it('preserves nullable values and clears earlier settings when cycling through all variants', function (): void {
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, function (): void {
        $monitor = Monitor::factory()->tls()->create();
        $variants = [
            new TlsMonitorConfiguration('example.invalid', 443, 0),
            new DnsMonitorConfiguration('example.invalid', DnsRecordType::AAAA, ['2001:db8::1']),
            new HeartbeatMonitorConfiguration(0),
            new HttpMonitorConfiguration('https://example.invalid', body: ''),
            new HttpMonitorConfiguration('https://example.invalid', body: null),
        ];

        foreach ($variants as $configuration) {
            $monitor->applyConfiguration($configuration);
            $monitor->save();
            expect($monitor->fresh()->configuration())->toEqual($configuration);
        }
    });
});

it('persists canonical regions and immutable UTC observation fields with independent pause flags', function (): void {
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, function (): void {
        $monitor = Monitor::factory()->create();
        $monitor->regions_json = new MonitorRegions([MonitorRegion::Us, MonitorRegion::Eu]);
        $monitor->is_paused = true;
        $monitor->is_plan_suspended = true;
        $instant = \Carbon\CarbonImmutable::parse('2026-10-25 02:30:00+02:00');
        $monitor->next_check_at = $instant;
        $monitor->last_checked_at = now()->subMinute();
        $monitor->ssl_expires_at = now()->addDays(30);
        $monitor->save();
        $fresh = $monitor->fresh();
        expect(json_decode(DB::table('monitors')->where('id', $monitor->id)->value('regions_json'), true))->toBe(['eu', 'us'])
            ->and($fresh->regions_json->values)->toBe([MonitorRegion::Eu, MonitorRegion::Us])
            ->and($fresh->next_check_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
            ->and($fresh->last_checked_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
            ->and($fresh->ssl_expires_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
            ->and($fresh->next_check_at->utc()->timestamp)->toBe($instant->timestamp)
            ->and($fresh->next_check_at->format('Y-m-d H:i:s'))->toBe('2026-10-25 00:30:00');
        $fresh->is_paused = false;
        $fresh->save();
        expect($fresh->fresh()->is_plan_suspended)->toBeTrue();
    });
});

it('rejects malformed storage even through raw SQL that bypasses DTOs and model events', function (array $changes): void {
    $team = Team::factory()->create();
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create());
    expect(fn () => DB::transaction(fn () => DB::table('monitors')->where('id', $monitor->id)->update($changes)))->toThrow(QueryException::class);
    expect(app(TenantContext::class)->run($team->id, fn () => $monitor->fresh()->type))->toBe($monitor->type);
})->with([
    'unknown type' => [['type' => 'unsupported']],
    'unknown status' => [['last_status' => 'healthy']],
    'empty label' => [['name' => ' ']],
    'zero cadence' => [['interval_seconds' => 0]],
    'negative cadence' => [['interval_seconds' => -1]],
    'zero timeout' => [['timeout_ms' => 0]],
    'zero threshold' => [['confirmation_threshold' => 0]],
    'negative counter' => [['consecutive_failures' => -1]],
    'empty regions' => [['regions_json' => '[]']],
    'duplicate region' => [['regions_json' => '["eu","eu"]']],
    'unknown region' => [['regions_json' => '["apac"]']],
    'noncanonical regions' => [['regions_json' => '["us","eu"]']],
    'object regions' => [['regions_json' => '{}']],
    'null regions' => [['regions_json' => null]],
    'unknown verb' => [['method' => 'CONNECT']],
    'missing headers' => [['headers_json' => null]],
    'status below range' => [['expected_status' => 99]],
    'status above range' => [['expected_status' => 600]],
    'missing redirect choice' => [['follow_redirects' => null]],
    'missing keyword fields' => [['type' => 'keyword']],
    'irrelevant keyword' => [['expected_keyword' => 'text']],
    'irrelevant TLS' => [['tls_host' => 'host']],
    'irrelevant DNS' => [['dns_name' => 'host']],
    'irrelevant heartbeat' => [['heartbeat_grace_seconds' => 0]],
    'unknown team' => [['team_id' => 9223372036854775807]],
]);

it('constrains DNS JSON shape and typed optional values at the database boundary', function (string $variant, array $changes): void {
    $team = Team::factory()->create();
    $monitor = app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->{$variant}()->create());
    expect(fn () => DB::transaction(fn () => DB::table('monitors')->where('id', $monitor->id)->update($changes)))->toThrow(QueryException::class);
})->with([
    ['dns', ['dns_expected_values' => '[]']],
    ['dns', ['dns_expected_values' => '{}']],
    ['dns', ['dns_expected_values' => '[null]']],
    ['dns', ['dns_expected_values' => '[""]']],
    ['dns', ['dns_record_type' => 'SRV']],
    ['tls', ['tls_port' => 0]],
    ['tls', ['tls_port' => 65536]],
    ['tls', ['tls_expiry_warning_days' => -1]],
    ['heartbeat', ['heartbeat_grace_seconds' => -1]],
]);

it('isolates real monitor queries mutations reloads and team relations', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $context = app(TenantContext::class);
    $a = $context->run($alpha->id, fn () => Monitor::factory()->create());
    $b = $context->run($beta->id, fn () => Monitor::factory()->dns()->create());
    expect(fn () => Monitor::query()->count())->toThrow(MissingTenantContext::class);
    expect(fn () => $alpha->monitors()->count())->toThrow(MissingTenantContext::class);
    expect(fn () => $a->refresh())->toThrow(MissingTenantContext::class);
    $context->run($alpha->id, function () use ($alpha, $beta, $a, $b): void {
        expect(Monitor::query()->count())->toBe(1)
            ->and($alpha->monitors()->sole()->id)->toBe($a->id)
            ->and($beta->monitors()->count())->toBe(0)
            ->and($a->team->id)->toBe($alpha->id)
            ->and(Monitor::query()->whereKey($b->id)->update(['name' => 'forged']))->toBe(0)
            ->and(Monitor::query()->whereKey($b->id)->delete())->toBe(0)
            ->and($b->fresh())->toBeNull();
        expect(fn () => $b->refresh())->toThrow(ModelNotFoundException::class);
        expect(fn () => $b->delete())->toThrow(LogicException::class);
        expect(fn () => $beta->monitors()->save(Monitor::factory()->make()))->toThrow(LogicException::class);
        $a->team_id = $beta->id;
        expect(fn () => $a->save())->toThrow(LogicException::class);
    });
    $context->run($beta->id, function () use ($a, $beta): void {
        $a->team_id = $beta->id;
        expect(fn () => $a->save())->toThrow(LogicException::class);
    });
    expect($context->run($alpha->id, fn () => $a->fresh()->team_id))->toBe($alpha->id);
});

it('protects ownership configuration and operational state from mass assignment', function (): void {
    $monitor = new Monitor([
        'name' => 'Allowed label', 'team_id' => 999, 'type' => 'http', 'url' => 'secret',
        'interval_seconds' => 1, 'timeout_ms' => 1, 'regions_json' => ['us'], 'is_paused' => true,
        'is_plan_suspended' => true, 'last_status' => 'up', 'next_check_at' => now(), 'consecutive_failures' => 99,
    ]);
    expect($monitor->name)->toBe('Allowed label')->and($monitor->team_id)->toBeNull()
        ->and($monitor->type)->toBeNull()->and($monitor->url)->toBeNull()
        ->and($monitor->interval_seconds)->toBeNull()->and($monitor->timeout_ms)->toBeNull()
        ->and($monitor->regions_json->toValues())->toBe(['eu'])
        ->and($monitor->is_paused)->toBeFalse()->and($monitor->is_plan_suspended)->toBeFalse()
        ->and($monitor->last_status)->toBe(MonitorStatus::Unknown)->and($monitor->next_check_at)->toBeNull()
        ->and($monitor->consecutive_failures)->toBe(0);
});

it('requires complete configuration before saving and rejects untyped region assignment', function (): void {
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, function (): void {
        expect(fn () => (new Monitor(['name' => 'Incomplete']))->save())->toThrow(LogicException::class);
        $monitor = Monitor::factory()->make();
        expect(fn () => $monitor->regions_json = ['eu'])->toThrow(InvalidArgumentException::class);
        expect(Monitor::query()->count())->toBe(0);
    });
});

it('creates every explicit factory variant without provisioning any additional team', function (string $variant): void {
    expect(fn () => Monitor::factory()->{$variant}()->make())->toThrow(MissingTenantContext::class);
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, function () use ($variant): void {
        $monitor = Monitor::factory()->{$variant}()->create();
        expect($monitor->type->value)->toBe($variant)->and($monitor->configuration()->type())->toBe($monitor->type);
    });
    expect(Team::query()->count())->toBe(1);
})->with(['http', 'keyword', 'tls', 'dns', 'heartbeat']);

it('preserves identity on monitor rollback and uses the declared cascading owner relationship', function (): void {
    $team = Team::factory()->create();
    app(TenantContext::class)->run($team->id, fn () => Monitor::factory()->create());
    $migration = require base_path('database/migrations/2026_10_22_000006_create_monitors_table.php');
    $scheduling = require base_path('database/migrations/2026_10_23_000010_create_check_executions_table.php');
    // Roll back dependent executions first, following the real migration order.
    $scheduling->down();
    $migration->down();
    expect(Schema::hasTable('monitors'))->toBeFalse()->and(Team::query()->count())->toBe(1)->and(User::query()->count())->toBe(1);
    $migration->up();
    $scheduling->up();
    app(TenantContext::class)->run($team->id, function () use ($team): void {
        expect(Monitor::query()->count())->toBe(0);
        Monitor::factory()->create();
        $team->delete();
        expect(Monitor::query()->count())->toBe(0);
    });
    expect(User::query()->count())->toBe(1);
});

it('creates tenant and due scheduling indexes with the expected partial eligibility predicate', function (): void {
    $indexes = DB::table('pg_indexes')->where('tablename', 'monitors')->pluck('indexdef', 'indexname');
    expect($indexes['monitors_team_id_id_index'])->toContain('(team_id, id)');
    expect($indexes['monitors_due_check_idx'])->toContain('(next_check_at, id)', 'NOT is_paused', 'NOT is_plan_suspended', 'next_check_at IS NOT NULL');
});
