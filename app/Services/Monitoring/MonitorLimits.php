<?php

declare(strict_types=1);

/** Apply plan quotas and cadence minimums to monitor mutations from every entry point. */

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorUsage;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Mutating callers must hold the Team row lock and an active tenant context for that team, so the count
 * and the following write are serialized against concurrent creations and plan changes.
 * Queries add an explicit team predicate in addition to TeamScope.
 */
final readonly class MonitorLimits
{
    /**
     * @param PlanLimits $limits Single plan definition.
     * @param TeamEntitlement $entitlement Effective plan resolution.
     */
    public function __construct(private PlanLimits $limits, private TeamEntitlement $entitlement)
    {
    }

    /**
     * @param Team $team Locked team about to receive one more monitor.
     * @throws ValidationException On key `monitor` when the team already holds its plan's maximum.
     */
    public function assertCanCreate(Team $team): void
    {
        $limit = $this->limits->monitors($this->entitlement->effectivePlan($team));

        // Paused and plan-suspended monitors occupy quota too, so pausing never frees a creation slot.
        if (Monitor::query()->where('team_id', $team->id)->count() >= $limit) {
            throw ValidationException::withMessages([
                'monitor' => "Your plan allows {$limit} monitors. Delete a monitor or upgrade to add another.",
            ]);
        }
    }

    /**
     * @param Team $team Locked team owning the monitor.
     * @param int $seconds Requested or current interval in seconds.
     * @throws ValidationException On key `interval_seconds` when the cadence is faster than the plan allows.
     */
    public function assertInterval(Team $team, int $seconds): void
    {
        $minimum = $this->limits->minimumIntervalSeconds($this->entitlement->effectivePlan($team));

        if ($seconds < $minimum) {
            throw ValidationException::withMessages([
                'interval_seconds' => "Your plan requires an interval of at least {$minimum} seconds.",
            ]);
        }
    }

    /**
     * Read-only quota snapshot for pages; it needs tenant context but no lock, so it may be stale by the
     * time a mutation runs, which then re-checks under the lock.
     *
     * @param Team $team Team whose effective plan and monitor count are summarized.
     * @return MonitorUsage Current count, plan maximum and plan minimum interval.
     */
    public function usage(Team $team): MonitorUsage
    {
        $plan = $this->entitlement->effectivePlan($team);

        return new MonitorUsage(
            used: Monitor::query()->where('team_id', $team->id)->count(),
            limit: $this->limits->monitors($plan),
            minimumIntervalSeconds: $this->limits->minimumIntervalSeconds($plan),
        );
    }

    /**
     * Deterministic ADR-010 position: oldest monitors by creation time, then ID, keep their places.
     *
     * @param Team $team Owning team; mutations pass the locked row, pages an unlocked read.
     * @param Monitor $monitor Persisted monitor of that team.
     * @param CarbonImmutable|null $at Admission instant, shared with scheduling cadence checks when supplied.
     * @return bool Whether the monitor's position is inside the current plan quota.
     */
    public function isWithinQuota(Team $team, Monitor $monitor, ?CarbonImmutable $at = null): bool
    {
        $limit = $this->limits->monitors($this->entitlement->effectivePlan($team, $at));

        $ahead = Monitor::query()
            ->where('team_id', $team->id)
            ->where(function (Builder $query) use ($monitor): void {
                $query->where('created_at', '<', $monitor->created_at)
                    ->orWhere(function (Builder $tied) use ($monitor): void {
                        $tied->where('created_at', $monitor->created_at)->where('id', '<', $monitor->id);
                    });
            })
            ->count();

        return $ahead < $limit;
    }
}
