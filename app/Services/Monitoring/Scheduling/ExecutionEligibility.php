<?php

declare(strict_types=1);

/** Share lock-aware operational and plan admission between reservation and acquisition. */

namespace App\Services\Monitoring\Scheduling;

use App\Models\Monitor;
use App\Models\Team;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use App\Services\Monitoring\MonitorLimits;
use Carbon\CarbonImmutable;

/** Callers hold Team then Monitor locks and establish the owning tenant context. */
final readonly class ExecutionEligibility
{
    /**
     * @param TeamEntitlement $entitlement Effective plan and exclusive expiry resolution.
     * @param PlanLimits $plans Single entitlement matrix.
     * @param MonitorLimits $monitors Deterministic quota position resolver.
     */
    public function __construct(private TeamEntitlement $entitlement, private PlanLimits $plans, private MonitorLimits $monitors)
    {
    }

    /**
     * @param Team $team Locked owner.
     * @param Monitor $monitor Locked tenant-owned configuration.
     * @param CarbonImmutable $at Admission instant after lock acquisition.
     * @return bool Whether new work may be reserved or started without changing billing state.
     */
    public function allows(Team $team, Monitor $monitor, CarbonImmutable $at): bool
    {
        $plan = $this->entitlement->effectivePlan($team, $at);

        return !$monitor->is_paused
            && !$monitor->is_plan_suspended
            && $monitor->interval_seconds >= $this->plans->minimumIntervalSeconds($plan)
            && $this->monitors->isWithinQuota($team, $monitor, $at);
    }
}
