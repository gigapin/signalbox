<?php

declare(strict_types=1);

/** Deliver a persisted execution identity; application check strategies remain separate runner work. */

namespace App\Jobs;

use App\Contracts\Monitoring\CheckWorkConsumer;
use App\Contracts\Tenancy\TenantJob;
use App\Jobs\Middleware\UseTenantContext;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/** Duplicate deliveries cannot acquire twice; killed workers are terminalized by persistent recovery. */
final class DeliverCheckExecution implements ShouldQueue, TenantJob
{
    /** @var int One delivery attempt; outbox redelivery only applies before acquisition. */
    public int $tries = 1;

    /** @var int Worker timeout in seconds, below the 35-second ledger deadline and Redis retry_after. */
    public int $timeout = 30;

    /**
     * @param int $teamId Scalar tenant boundary, never a serialized Eloquent model.
     * @param int $executionId Persisted regional execution identity.
     */
    public function __construct(public readonly int $teamId, public readonly int $executionId)
    {
    }

    /** @return int Tenant identity carried by the committed outbox. */
    public function tenantId(): int
    {
        return $this->teamId;
    }

    /** @return list<UseTenantContext> Restore the worker's previous context after every delivery. */
    public function middleware(): array
    {
        return [new UseTenantContext()];
    }

    /**
     * The consumer is deliberately unbound until a real runner exists; enabling requires that integration.
     *
     * @param ExecutionLedger $ledger Atomic admission and fenced infrastructure-failure transitions.
     * @param CheckWorkConsumer $consumer Runner responsible for atomic result persistence and ledger completion.
     * @throws Throwable On infrastructure failure, after recording a fenced failure when still owned.
     */
    public function handle(ExecutionLedger $ledger, CheckWorkConsumer $consumer): void
    {
        $work = $ledger->acquire($this->teamId, $this->executionId);

        if ($work === null) {
            return;
        }

        try {
            $consumer->consume($work);
        } catch (Throwable $exception) {
            $ledger->finish($work, true);

            throw $exception;
        }
    }
}
