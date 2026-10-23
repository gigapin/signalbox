<?php

declare(strict_types=1);

/** Verify inert defaults and sub-minute scheduler registration without database writes. */

use App\Contracts\Monitoring\CheckWorkConsumer;
use App\Contracts\Monitoring\CheckWorkPublisher;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Monitoring\Scheduling\PublishMonitorChecks;
use App\Services\Monitoring\Scheduling\RecoverMonitorChecks;
use App\Services\Monitoring\Scheduling\RedisCheckWorkPublisher;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use Illuminate\Console\Scheduling\Schedule;

it('keeps all entry points inert while scheduling is disabled', function (): void {
    config(['monitor-scheduling.enabled' => false]);
    expect(app(ReserveMonitorChecks::class)->run())->toBe(0)
        ->and(app(PublishMonitorChecks::class)->run())->toBe(0)
        ->and(app(RecoverMonitorChecks::class)->run())->toBe(0)
        ->and(app(ExecutionLedger::class)->leasePublication(1, 1))->toBeNull()
        ->and(app(ExecutionLedger::class)->acquire(1, 1))->toBeNull()
        ->and(app(ExecutionLedger::class)->recover(1, 1))->toBeFalse();

    foreach (['dispatch', 'publish', 'recover'] as $operation) {
        $this->artisan('monitors:'.$operation)
            ->expectsOutput('Monitor scheduling is disabled.')
            ->assertSuccessful();
    }
});

it('registers gated second-level commands with shared cache coordination', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'monitors:'));

    expect($events)->toHaveCount(3);
    config(['monitor-scheduling.enabled' => false]);

    foreach ($events as $event) {
        expect($event->repeatSeconds)->toBe(1)
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(1)
            ->and($event->filtersPass(app()))->toBeFalse();
    }

    config(['monitor-scheduling.enabled' => true]);

    foreach ($events as $event) {
        expect($event->filtersPass(app()))->toBeTrue();
    }
});

it('binds the real broker adapter without installing a fake checker', function (): void {
    expect(app(CheckWorkPublisher::class))->toBeInstanceOf(RedisCheckWorkPublisher::class)
        ->and(app()->bound(CheckWorkConsumer::class))->toBeFalse()
        ->and(config('monitor-scheduling.chunk_size'))->toBeGreaterThan(0)
        ->and(config('monitor-scheduling.publication_lease_seconds'))->toBe(10)
        ->and(config('monitor-scheduling.redelivery_seconds'))->toBe(5);
});
