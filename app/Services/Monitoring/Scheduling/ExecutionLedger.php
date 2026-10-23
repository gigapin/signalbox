<?php

declare(strict_types=1);

/** Own publication leases, single acquisition and fenced terminal transitions. */

namespace App\Services\Monitoring\Scheduling;

use App\Models\CheckExecution;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All transitions lock the execution row; acquiring also locks Team then Monitor before that row. */
final readonly class ExecutionLedger
{
    /**
     * @param TenantContext $context Restored after every bounded operation, including exceptions.
     * @param ExecutionEligibility $eligibility Revalidate current configuration and plan before admission.
     */
    public function __construct(private TenantContext $context, private ExecutionEligibility $eligibility)
    {
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
     * @return CheckExecution|null Single acquired owner; duplicate, expired or inadmissible work returns null.
     */
    public function acquire(int $teamId, int $id): ?CheckExecution
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return null;
        }

        return $this->context->run($teamId, fn (): ?CheckExecution => DB::transaction(function () use ($teamId, $id): ?CheckExecution {
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

            // Horizon kills this delivery after 30 seconds; allow five seconds before abandonment recovery.
            $work->forceFill([
                'state' => 'running',
                'acquisition_token' => (string) Str::uuid(),
                'started_at' => $now,
                'deadline_at' => $now->addSeconds(35),
                'publication_token' => null,
                'publication_lease_until' => null,
            ])->save();

            return $work;
        }));
    }

    /**
     * Call inside the same database transaction as future result persistence; false forbids result writes.
     *
     * @param CheckExecution $acquired Original execution containing its acquisition fence.
     * @param bool $infrastructureFailure Whether the consumer failed outside target check semantics.
     * @return bool Whether this owner completed before its deadline; terminal or late owners cannot overwrite state.
     */
    public function finish(CheckExecution $acquired, bool $infrastructureFailure = false): bool
    {
        return $this->locked($acquired->team_id, $acquired->id, function (CheckExecution $work, CarbonImmutable $now) use ($acquired, $infrastructureFailure): bool {
            if ($work->state !== 'running' || $work->acquisition_token !== $acquired->acquisition_token) {
                return false;
            }

            if ($work->deadline_at === null || $work->deadline_at->lessThanOrEqualTo($now)) {
                $this->terminalize($work, 'infrastructure_failed', $now);

                return false;
            }

            $this->terminalize($work, $infrastructureFailure ? 'infrastructure_failed' : 'completed', $now);

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
        return $this->context->run($teamId, fn (): mixed => DB::transaction(function () use ($id, $operation): mixed {
            $work = CheckExecution::query()->lockForUpdate()->find($id);

            return $work === null ? null : $operation($work, CarbonImmutable::now('UTC'));
        }));
    }

    /**
     * @param CheckExecution $work Row-locked execution.
     * @param string $state Terminal delivery outcome; never a target up/down value.
     * @param CarbonImmutable $now Terminal transition instant.
     */
    private function terminalize(CheckExecution $work, string $state, CarbonImmutable $now): void
    {
        $work->forceFill([
            'state' => $state,
            'finished_at' => $now,
            'publication_token' => null,
            'publication_lease_until' => null,
        ])->save();
    }
}
