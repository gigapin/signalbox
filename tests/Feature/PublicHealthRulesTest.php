<?php

declare(strict_types=1);

/** Check the pure rules deciding what a status page says about monitors and groups. */

use App\Enums\Monitoring\MonitorStatus;
use App\Enums\StatusPages\PublicHealth;
use App\Models\Monitor;
use App\Services\StatusPages\PublicHealthRules;
use Carbon\CarbonImmutable;

/**
 * @param array<string, mixed> $attributes Replaced attributes.
 * @return Monitor Unsaved watched monitor, checked one minute before the reference instant, every minute.
 */
function healthMonitor(array $attributes = []): Monitor
{
    $monitor = new Monitor();
    $monitor->forceFill([
        'is_paused' => false,
        'is_plan_suspended' => false,
        'last_status' => MonitorStatus::Up,
        'last_checked_at' => CarbonImmutable::parse('2026-11-01T11:59:00Z'),
        'interval_seconds' => 60,
        'heartbeat_grace_seconds' => null,
        ...$attributes,
    ]);

    return $monitor;
}

it('decides the state of a monitor', function (array $attributes, bool $incident, bool $maintenance, ?PublicHealth $expected): void {
    $now = CarbonImmutable::parse('2026-11-01T12:00:00Z');

    expect((new PublicHealthRules())->monitor(healthMonitor($attributes), $incident, $maintenance, $now))->toBe($expected);
})->with([
    'recent up' => [[], false, false, PublicHealth::Operational],
    'unconfirmed failure stays operational' => [['last_status' => MonitorStatus::Down], false, false, PublicHealth::Operational],
    'confirmed incident' => [[], true, false, PublicHealth::Outage],
    'incident beats maintenance' => [[], true, true, PublicHealth::Outage],
    'maintenance' => [[], false, true, PublicHealth::Maintenance],
    'never observed' => [['last_status' => MonitorStatus::Unknown, 'last_checked_at' => null], false, false, PublicHealth::Unknown],
    'unknown observation' => [['last_status' => MonitorStatus::Unknown], false, false, PublicHealth::Unknown],
    'exactly at the expiry' => [['last_checked_at' => CarbonImmutable::parse('2026-11-01T11:58:00Z')], false, false, PublicHealth::Operational],
    'one second past the expiry' => [['last_checked_at' => CarbonImmutable::parse('2026-11-01T11:57:59Z')], false, false, PublicHealth::Unknown],
    'heartbeat grace extends the expiry' => [['last_checked_at' => CarbonImmutable::parse('2026-11-01T11:57:59Z'), 'heartbeat_grace_seconds' => 30], false, false, PublicHealth::Operational],
    'paused is not watched' => [['is_paused' => true], true, false, null],
    'suspended is not watched' => [['is_plan_suspended' => true], false, false, null],
]);

it('decides the state of a group', function (array $parts, PublicHealth $expected): void {
    expect((new PublicHealthRules())->group($parts))->toBe($expected);
})->with([
    'empty' => [[], PublicHealth::Unknown],
    'nothing watched' => [[null, null], PublicHealth::Unknown],
    'all operational' => [[PublicHealth::Operational, null], PublicHealth::Operational],
    'all outage' => [[PublicHealth::Outage, PublicHealth::Outage, null], PublicHealth::Outage],
    'some outage' => [[PublicHealth::Outage, PublicHealth::Operational], PublicHealth::PartialOutage],
    'a partial part' => [[PublicHealth::PartialOutage, PublicHealth::Outage], PublicHealth::PartialOutage],
    'outage beats maintenance' => [[PublicHealth::Outage, PublicHealth::Maintenance], PublicHealth::PartialOutage],
    'maintenance beats unknown' => [[PublicHealth::Maintenance, PublicHealth::Unknown], PublicHealth::Maintenance],
    'unknown beats operational' => [[PublicHealth::Unknown, PublicHealth::Operational], PublicHealth::Unknown],
]);
