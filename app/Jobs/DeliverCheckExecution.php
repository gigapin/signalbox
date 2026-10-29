<?php

declare(strict_types=1);

/** Preserve scalar single-execution messages while delegating application work to transactional runner admission. */

namespace App\Jobs;

use App\Contracts\Monitoring\BatchCheckWorkConsumer;
use App\Contracts\Monitoring\CheckWorkConsumer;
use App\Contracts\Tenancy\TenantJob;
use App\Jobs\Middleware\UseTenantContext;
use App\Models\CheckExecution;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Duplicate deliveries cannot acquire twice; killed workers are terminalized by persistent recovery. */
final class DeliverCheckExecution implements ShouldQueue, TenantJob
{
    use InteractsWithQueue;

    /** @var bool Run the restored failed hook after a worker timeout. */
    public bool $failOnTimeout = true;

    /** @var int One delivery attempt; outbox redelivery only applies before acquisition. */
    public int $tries = 1;

    /** @var int Worker timeout in seconds, below the 50-second ledger deadline and Redis retry_after. */
    public int $timeout = 45;

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

    /** @return list<UseTenantContext|\Illuminate\Queue\Middleware\WithoutOverlapping> Restore the worker's previous context after every delivery. */
    public function middleware(): array
    {
        $identity = app(TenantContext::class)->run($this->teamId, fn (): ?CheckExecution => CheckExecution::query()->find($this->executionId));

        return $identity === null ? [new UseTenantContext()] : (new DeliverCheckBatch($this->teamId, [$identity->id => $identity->monitor_id], $identity->region))->middleware();
    }

    /**
     * Preserve legacy envelopes while real consumers use transactional snapshot admission.
     *
     * @param ExecutionLedger $ledger Atomic admission and fenced infrastructure-failure transitions.
     * @param CheckWorkConsumer $consumer Runner responsible for atomic result persistence and ledger completion.
     * @throws Throwable On infrastructure failure, after recording a fenced failure when still owned.
     */
    public function handle(ExecutionLedger $ledger, CheckWorkConsumer $consumer): void
    {
        if ($consumer instanceof BatchCheckWorkConsumer) {
            $identity = app(TenantContext::class)->run($this->teamId, fn (): ?CheckExecution => CheckExecution::query()->find($this->executionId));

            if ($identity !== null) {
                $uuid = $this->job?->payload()['uuid'] ?? null;
                $consumer->consumeBatch($this->teamId, [$identity->id => $identity->monitor_id], $identity->region, is_string($uuid) ? $uuid : null, is_string($uuid) ? $this->job->attempts() : null);
            }

            return;
        }

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
    /** @param Throwable|null $exception Raw worker failure is never a target diagnostic. */
    public function failed(?Throwable $exception): void
    {
        $uuid = $this->job?->payload()['uuid'] ?? null;

        if (is_string($uuid) && $this->job !== null) {
            app(ExecutionLedger::class)->failDelivery($this->teamId, [$this->executionId], $uuid, $this->job->attempts());
        }
    }
}
