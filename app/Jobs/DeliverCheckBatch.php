<?php

declare(strict_types=1);

/** Deliver a bounded regional batch using scalar identities and persisted broker ownership. */

namespace App\Jobs;

use App\Contracts\Monitoring\BatchCheckWorkConsumer;
use App\Contracts\Tenancy\TenantJob;
use App\Jobs\Middleware\UseTenantContext;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/** Busy monitor locks discard this delivery; the unacquired outbox retains responsibility for redelivery. */
final class DeliverCheckBatch implements ShouldQueue, TenantJob
{
    use InteractsWithQueue;

    /** @var int Delivery failures never repeat an acquired target observation. */
    public int $tries = 1;
    /** @var int Worker seconds, below the 50-second ledger deadline. */
    public int $timeout = 45;
    /** @var bool Ask Laravel to invoke the fresh failed hook on timeout. */
    public bool $failOnTimeout = true;

    /**
     * @param int $teamId Scalar tenant boundary.
     * @param array<int, int> $executions Execution IDs mapped to distinct monitor IDs, never private inputs.
     * @param string $region Simulated regional queue identity.
     */
    public function __construct(
        public readonly int $teamId,
        public readonly array $executions,
        public readonly string $region,
    ) {
    }

    /** @return int Explicit tenant identity. */
    public function tenantId(): int
    {
        return $this->teamId;
    }

    /** @return list<UseTenantContext|WithoutOverlapping> Sorted shared monitor locks expire after the execution deadline. */
    public function middleware(): array
    {
        $ids = array_values($this->executions);
        sort($ids, SORT_NUMERIC);
        $middleware = [new UseTenantContext()];

        foreach ($ids as $id) {
            $middleware[] = (new WithoutOverlapping('monitor-check:'.$this->teamId.':'.$id.':'.$this->region))->shared()->dontRelease()->expireAfter(55);
        }

        return $middleware;
    }

    /** @param BatchCheckWorkConsumer $consumer Real admission, strategy and persistence orchestrator. */
    public function handle(BatchCheckWorkConsumer $consumer): void
    {
        $uuid = $this->job?->payload()['uuid'] ?? null;
        $consumer->consumeBatch($this->teamId, $this->executions, $this->region, is_string($uuid) ? $uuid : null, is_string($uuid) ? $this->job->attempts() : null);
    }

    /** @param Throwable|null $exception Deliberately excluded from persisted target diagnostics. */
    public function failed(?Throwable $exception): void
    {
        $uuid = $this->job?->payload()['uuid'] ?? null;

        if (is_string($uuid) && $this->job !== null) {
            app(ExecutionLedger::class)->failDelivery($this->teamId, array_keys($this->executions), $uuid, $this->job->attempts());
        }
    }
}
