<?php

declare(strict_types=1);

/** Own publication leases, single acquisition and fenced terminal transitions. */

namespace App\Services\Monitoring\Scheduling;

use App\Data\Monitoring\Checks\CheckOutcome;
use App\Models\CheckExecution;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\Results\ExecutionResults;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All transitions lock Team then Monitor then the execution row; terminal transitions also append their result. */
final readonly class ExecutionLedger
{
    /**
     * @param TenantContext $context Restored after every bounded operation, including exceptions.
     * @param ExecutionEligibility $eligibility Revalidate current configuration and plan before admission.
     * @param ResultPartitions $partitions Ensure the immutable slot month before domain locks.
     * @param ExecutionResults $results Atomic append-only results and monitor summary updates.
     */
    public function __construct(
        private TenantContext $context,
        private ExecutionEligibility $eligibility,
        private ResultPartitions $partitions,
        private ExecutionResults $results,
    ) {
    }

    /**
     * @param int $teamId Discovered owner.
     * @param int $id Outbox identity.
     * @return CheckExecution|null Leased pending work, or null when unavailable or disabled.
     */
    public function leasePublication(int $teamId, int $id): ?CheckExecution
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return null;
        }

        return $this->locked($teamId, $id, function (CheckExecution $work, CarbonImmutable $now): ?CheckExecution {
            if ($work->state !== 'pending' || $work->expires_at->lessThanOrEqualTo($now)
                || $work->available_at->isAfter($now)
                || $work->publication_lease_until?->isAfter($now)) {
                return null;
            }

            $until = $now->addSeconds((int) config('monitor-scheduling.publication_lease_seconds'));
            $work->forceFill([
                'publication_token' => (string) Str::uuid(),
                'publication_lease_until' => $until,
                'available_at' => $until,
            ])->save();

            return $work;
        });
    }

    /**
     * @param CheckExecution $leased Scalar identity and publication fence returned before broker I/O.
     * @return bool Whether acknowledgement still owned the pending publication lease.
     */
    public function acknowledge(CheckExecution $leased): bool
    {
        return $this->locked($leased->team_id, $leased->id, function (CheckExecution $work, CarbonImmutable $now) use ($leased): bool {
            if ($work->state !== 'pending' || $work->publication_token !== $leased->publication_token
                || $work->publication_lease_until === null || $work->publication_lease_until->lessThanOrEqualTo($now)) {
                return false;
            }

            $work->forceFill([
                'publication_token' => null,
                'publication_lease_until' => null,
                'published_at' => $now,
                'available_at' => $now->addSeconds((int) config('monitor-scheduling.redelivery_seconds')),
            ])->save();

            return true;
        }) ?? false;
    }

    /**
     * @param int $teamId Envelope tenant identity; forged ownership cannot load another tenant's work.
     * @param int $id Execution identifier, never an Eloquent serialized model.
     * @param string|null $queueUuid Owning Redis message UUID; null denotes a legacy direct caller.
     * @param int|null $queueAttempt Owning broker attempt, required together with a message UUID.
     * @return CheckExecution|null Single acquired owner; duplicate, expired or inadmissible work returns null.
     */
    public function acquire(int $teamId, int $id, ?string $queueUuid = null, ?int $queueAttempt = null): ?CheckExecution
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return null;
        }

        $this->preparePartition($teamId, $id);

        return $this->context->run($teamId, fn (): ?CheckExecution => DB::transaction(function () use ($teamId, $id, $queueUuid, $queueAttempt): ?CheckExecution {
            $identity = CheckExecution::query()->find($id, ['id', 'monitor_id']);

            if ($identity === null) {
                return null;
            }

            $team = Team::query()->lockForUpdate()->find($teamId);
            $monitor = Monitor::query()->where('team_id', $teamId)->lockForUpdate()->find($identity->monitor_id);
            $work = CheckExecution::query()->lockForUpdate()->find($id);
            $now = CarbonImmutable::now('UTC');

            if ($work === null || $work->state !== 'pending') {
                return null;
            }

            if ($work->expires_at->lessThanOrEqualTo($now)) {
                $this->terminalize($work, 'missed', $now);

                return null;
            }

            if ($team === null || $monitor === null || !$this->eligibility->allows($team, $monitor, $now)
                || $work->monitor_revision !== $monitor->execution_revision) {
                $this->terminalize($work, 'cancelled', $now);

                return null;
            }

            // Regional workers stop after 45 seconds; five additional seconds fence abandonment recovery.
            $work->forceFill([
                'state' => 'running',
                'acquisition_token' => (string) Str::uuid(),
                'started_at' => $now,
                'deadline_at' => $now->addSeconds(50),
                'queue_uuid' => $queueUuid,
                'queue_attempt' => $queueAttempt,
                'publication_token' => null,
                'publication_lease_until' => null,
            ])->save();

            return $work;
        }));
    }

    /**
     * Atomically persist the result and summary with the terminal transition; false rejects a stale observation.
     *
     * @param CheckExecution $acquired Original execution containing its acquisition fence.
     * @param bool $infrastructureFailure Whether the consumer failed outside target check semantics.
     * @param CheckOutcome|null $outcome Genuine checker observation, absent for infrastructure or legacy unobserved completion.
     * @return bool Whether this owner completed before its deadline; terminal or late owners cannot overwrite state.
     */
    public function finish(CheckExecution $acquired, bool $infrastructureFailure = false, ?CheckOutcome $outcome = null): bool
    {
        return $this->locked($acquired->team_id, $acquired->id, function (CheckExecution $work, CarbonImmutable $now) use ($acquired, $infrastructureFailure, $outcome): bool {
            if ($work->state !== 'running' || $work->acquisition_token !== $acquired->acquisition_token) {
                return false;
            }

            if ($work->deadline_at === null || $work->deadline_at->lessThanOrEqualTo($now)) {
                $this->terminalize($work, 'infrastructure_failed', $now);

                return false;
            }

            $this->terminalize($work, $infrastructureFailure ? 'infrastructure_failed' : 'completed', $now, $infrastructureFailure ? null : $outcome);

            return true;
        }) ?? false;
    }

    /**
     * @param int $teamId Discovered owner.
     * @param int $id Candidate execution identity.
     * @return bool Whether overdue work became terminal; a started check is never published again.
     */
    public function recover(int $teamId, int $id): bool
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return false;
        }

        return $this->locked($teamId, $id, function (CheckExecution $work, CarbonImmutable $now): bool {
            if ($work->state === 'pending' && $work->expires_at->lessThanOrEqualTo($now)) {
                $this->terminalize($work, 'missed', $now);

                return true;
            }

            if ($work->state === 'running' && $work->deadline_at?->lessThanOrEqualTo($now)) {
                $this->terminalize($work, 'infrastructure_failed', $now);

                return true;
            }

            return false;
        }) ?? false;
    }

    /**
     * @template TResult
     * @param int $teamId Explicit private boundary.
     * @param int $id Execution identity.
     * @param Closure(CheckExecution, CarbonImmutable): TResult $operation Row-locked transition.
     * @return TResult|null Null when the work was deleted or belongs to another tenant.
     */
    private function locked(int $teamId, int $id, Closure $operation): mixed
    {
        $this->preparePartition($teamId, $id);

        return $this->context->run($teamId, fn (): mixed => DB::transaction(function () use ($teamId, $id, $operation): mixed {
            $identity = CheckExecution::query()->find($id, ['id', 'monitor_id']);

            if ($identity === null) {
                return null;
            }

            Team::query()->lockForUpdate()->find($teamId);
            Monitor::query()->where('team_id', $teamId)->lockForUpdate()->find($identity->monitor_id);
            $work = CheckExecution::query()->lockForUpdate()->find($id);

            return $work === null ? null : $operation($work, CarbonImmutable::now('UTC'));
        }));
    }

    /**
     * @param CheckExecution $work Row-locked execution.
     * @param string $state Terminal delivery outcome; never a target up/down value.
     * @param CarbonImmutable $now Terminal transition instant.
     * @param CheckOutcome|null $outcome Genuine observed target result, never an inferred success.
     */
    private function terminalize(CheckExecution $work, string $state, CarbonImmutable $now, ?CheckOutcome $outcome = null): void
    {
        $work->forceFill([
            'state' => $state,
            'finished_at' => $now,
            'publication_token' => null,
            'publication_lease_until' => null,
        ])->save();
        $this->results->record($work, $outcome, $now);
    }

    /**
     * @param int $teamId Explicit owner, used before reading the slot date.
     * @param int $id Execution identity; a foreign or deleted execution performs no DDL.
     */
    private function preparePartition(int $teamId, int $id): void
    {
        $this->context->run($teamId, function () use ($id): void {
            $work = CheckExecution::query()->find($id, ['id', 'slot_at']);

            if ($work !== null) {
                $this->partitions->ensure($work->slot_at);
            }
        });
    }

    /**
     * @param int $teamId Previously validated scalar owner.
     * @param list<int> $ids Identities from the failed broker envelope.
     * @param string $uuid Broker UUID restored on Laravel's fresh failed-hook instance.
     * @param int $attempt Attempt number; another delivery attempt cannot adopt the owner's acquisition fence.
     * @return int Owned running executions terminalized without repeating target I/O.
     */
    public function failDelivery(int $teamId, array $ids, string $uuid, int $attempt): int
    {
        $count = 0;

        foreach ($ids as $id) {
            $count += (int) $this->locked($teamId, $id, function (CheckExecution $work, CarbonImmutable $now) use ($uuid, $attempt): bool {
                if ($work->state !== 'running' || $work->queue_uuid !== $uuid || $work->queue_attempt !== $attempt) {
                    return false;
                }

                $this->terminalize($work, 'infrastructure_failed', $now);

                return true;
            });
        }

        return $count;
    }
}
