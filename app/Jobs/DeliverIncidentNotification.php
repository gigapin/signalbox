<?php

declare(strict_types=1);

/** Deliver a scalar outbox identity under explicit tenant context; provider retries belong to persistent delivery state. */

namespace App\Jobs;

use App\Contracts\Tenancy\TenantJob;
use App\Jobs\Middleware\UseTenantContext;
use App\Services\Notifications\DeliverNotification;
use App\Services\Notifications\NotificationDeliveryLedger;
use App\Services\Notifications\NotificationDeliverySettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/** No models, message text, destination or credentials are serialized into the queue or failed_jobs. */
final class DeliverIncidentNotification implements ShouldQueue, TenantJob
{
    /** @var int One broker attempt; the outbox schedules bounded notification retries with its own backoff. */
    public int $tries = 1;

    /** @var int Worker kill deadline below the 75 s lease and Redis's 90 s retry_after. */
    public int $timeout = NotificationDeliverySettings::WORKER_TIMEOUT;

    /** @var bool Record a safe failure immediately when the worker times out. */
    public bool $failOnTimeout = true;

    /**
     * @param int $teamId Committed tenant identity.
     * @param int $deliveryId Committed delivery identity.
     * @param string $token Fence available even on the fresh job instance used by failed().
     */
    public function __construct(public readonly int $teamId, public readonly int $deliveryId, public readonly string $token)
    {
    }

    /** @return int Explicit tenant from the committed outbox publication. */
    public function tenantId(): int
    {
        return $this->teamId;
    }

    /** @return list<UseTenantContext> Always restore the preceding worker context, even on an error. */
    public function middleware(): array
    {
        return [new UseTenantContext()];
    }

    /**
     * @param DeliverNotification $deliveries Admit and execute one bounded provider attempt.
     * @throws \RuntimeException When an unexpected application failure is recorded safely.
     */
    public function handle(DeliverNotification $deliveries): void
    {
        $deliveries->run($this->teamId, $this->deliveryId, $this->token);
    }

    /** @param Throwable|null $exception Raw worker failure is deliberately never logged or persisted. */
    public function failed(#[\SensitiveParameter] ?Throwable $exception): void
    {
        app(NotificationDeliveryLedger::class)->fail($this->teamId, $this->deliveryId, $this->token, 'The delivery worker stopped before confirming its result.');
    }
}
