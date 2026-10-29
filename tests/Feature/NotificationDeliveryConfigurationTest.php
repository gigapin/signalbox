<?php

declare(strict_types=1);

/** Verify bounded worker/retry policy, dedicated queue provisioning and scalar-only delayed publications. */

use App\Jobs\DeliverIncidentNotification;
use App\Services\Notifications\NotificationDeliverySettings;
use App\Services\Notifications\RedisNotificationWorkPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\ProvisioningPlan;

it('keeps notification worker, lease and queue expiry in safe order without changing check retries', function (): void {
    $options = ProvisioningPlan::get('delivery-test')->optionsFor('*', 'notifications');
    $job = new DeliverIncidentNotification(1, 2, '12345678-1234-4234-8234-123456789012');
    $settings = app(NotificationDeliverySettings::class);
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'notifications:dispatch'));

    expect($options->queue)->toBe('notifications')
        ->and($options->timeout)->toBe($job->timeout)
        ->and($job->timeout)->toBeLessThan(NotificationDeliverySettings::LEASE_SECONDS)
        ->and(NotificationDeliverySettings::LEASE_SECONDS)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($settings->maxAttempts)->toBe(3)
        ->and($settings->retryDelay(1))->toBe(30)
        ->and($settings->retryDelay(2))->toBe(120)
        ->and($settings->retryDelay(3))->toBe(0)
        ->and($event->repeatSeconds)->toBe(10)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('publishes immediate and delayed scalar envelopes to Redis despite a synchronous default', function (): void {
    Queue::fake();
    config(['queue.default' => 'sync']);
    $publisher = app(RedisNotificationWorkPublisher::class);
    $publisher->publish(11, 21, CarbonImmutable::now()->subSecond());
    $publisher->publish(12, 22, CarbonImmutable::now()->addMinute());

    Queue::assertPushedOn('notifications', DeliverIncidentNotification::class, function (DeliverIncidentNotification $job): bool {
        $payload = get_object_vars($job);
        expect(array_keys($payload))->toBe(['tries', 'timeout', 'failOnTimeout', 'teamId', 'deliveryId', 'token']);

        return $job->teamId === 11 && $job->deliveryId === 21;
    });
    Queue::assertPushed(DeliverIncidentNotification::class, 2);
});

it('refuses unbounded or inconsistent notification delivery settings', function (array $values): void {
    config(['notifications.delivery' => $values]);

    expect(fn () => app(NotificationDeliverySettings::class))->toThrow(LogicException::class);
})->with([
    [['max_attempts' => 0, 'backoff_seconds' => [], 'coalesce_seconds' => 5]],
    [['max_attempts' => 6, 'backoff_seconds' => [1, 2, 3, 4, 5], 'coalesce_seconds' => 5]],
    [['max_attempts' => 3, 'backoff_seconds' => [30], 'coalesce_seconds' => 5]],
    [['max_attempts' => 3, 'backoff_seconds' => [30, 0], 'coalesce_seconds' => 5]],
    [['max_attempts' => 3, 'backoff_seconds' => [30, 3601], 'coalesce_seconds' => 5]],
    [['max_attempts' => 3, 'backoff_seconds' => [30, '120'], 'coalesce_seconds' => 5]],
    [['max_attempts' => 3, 'backoff_seconds' => [30, 120], 'coalesce_seconds' => 61]],
    [['max_attempts' => 3, 'backoff_seconds' => [30, 120], 'coalesce_seconds' => -1]],
]);
