<?php

declare(strict_types=1);

/** Verify rolling heartbeat deadlines independently of transport, storage and tenant context services. */

use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\HeartbeatSnapshot;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Services\Monitoring\Checks\HeartbeatStrategy;
use Carbon\CarbonImmutable;

it('evaluates initial and renewed deadlines at the scheduled reference slot', function (?int $receiptOffset, int $slotOffset, int $grace, bool $missed): void {
    $start = CarbonImmutable::parse('2026-10-26T12:00:00Z');
    $context = new CheckContext(1, 2, 3, MonitorRegion::Eu, $start->addSeconds($slotOffset));
    $input = new CheckInput($context, new HeartbeatMonitorConfiguration($grace), 1000);
    $snapshot = new HeartbeatSnapshot(1, 2, $start, $receiptOffset === null ? null : $start->addSeconds($receiptOffset));
    $result = (new HeartbeatStrategy())->evaluate($input, $snapshot, 300);

    expect($result->status)->toBe($missed ? MonitorStatus::Down : MonitorStatus::Up)
        ->and($result->error)->toBe($missed ? CheckError::HeartbeatMissed : null)
        ->and($result->latencyMs)->toBeNull()
        ->and($result->statusCode)->toBeNull();
})->with([
    'never called before expiry' => [null, 299, 0, false],
    'never called exact expiry' => [null, 300, 0, true],
    'never called beyond expiry' => [null, 301, 0, true],
    'inside grace' => [null, 300, 10, false],
    'exact grace expiry' => [null, 310, 10, true],
    'renewed' => [250, 500, 0, false],
    'renewed exact expiry' => [250, 550, 0, true],
    'receipt at slot' => [550, 550, 0, false],
]);

it('rejects foreign or temporally invalid snapshots', function (string $case): void {
    $start = CarbonImmutable::parse('2026-10-26T12:00:00Z');
    $context = new CheckContext(1, 2, 3, MonitorRegion::Eu, $start);
    $snapshot = new HeartbeatSnapshot($case === 'tenant' ? 2 : 1, $case === 'monitor' ? 3 : 2, $case === 'start' ? $start->addSecond() : $start, $case === 'future' ? $start->addSecond() : null);
    expect(fn () => (new HeartbeatStrategy())->evaluate(new CheckInput($context, new HeartbeatMonitorConfiguration(0), 1000), $snapshot, 300))->toThrow(InvalidArgumentException::class);
})->with(['tenant', 'monitor', 'start', 'future']);
