<?php

declare(strict_types=1);

/** Project effective plan, recorded trial and quota usage for current team members without operator data. */

namespace App\Services\Billing;

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/** This snapshot is advisory; mutation actions recheck the same authoritative limits under their locks. */
final readonly class TeamPlanSummary
{
    /**
     * @param TeamEntitlement $entitlements Existing precedence and exclusive-expiry resolver.
     * @param PlanLimits $limits Single versioned limits matrix.
     * @param TenantContext $context Fail-closed owning team.
     */
    public function __construct(private TeamEntitlement $entitlements, private PlanLimits $limits, private TenantContext $context)
    {
    }

    /**
     * @param Team $team Authorized route team, already admitted by TeamPolicy.
     * @return array{storedPlan: string, effectivePlan: string, source: string, expiresAt: string|null, trial: array{state: string, startsAt: string|null, endsAt: string|null}, monitors: array{used: int, limit: int}, statusPages: array{used: int, limit: int}, minimumIntervalSeconds: int, rollupRetentionDays: int, generatedAt: string} Deliberate member-safe snapshot without override reasons, operator identities or billing credentials.
     */
    public function forTeam(Team $team): array
    {
        abort_unless($team->id === $this->context->requireTeamId(), 404);
        $now = CarbonImmutable::now('UTC');
        $entitlement = $this->entitlements->resolve($team, $now);
        $trialEnd = $team->trial_ends_at;

        return [
            'storedPlan' => $team->plan->value,
            'effectivePlan' => $entitlement->plan->value,
            'source' => $entitlement->source->value,
            'expiresAt' => $entitlement->expiresAt?->toIso8601String(),
            'trial' => [
                'state' => $trialEnd->isAfter($now) ? 'active' : 'ended',
                'startsAt' => $team->trial_started_at->toIso8601String(),
                'endsAt' => $trialEnd->toIso8601String(),
            ],
            'monitors' => [
                'used' => Monitor::query()->where('team_id', $team->id)->count(),
                'limit' => $this->limits->monitors($entitlement->plan),
            ],
            'statusPages' => [
                'used' => StatusPage::query()->where('team_id', $team->id)->count(),
                'limit' => $this->limits->statusPages($entitlement->plan),
            ],
            'minimumIntervalSeconds' => $this->limits->minimumIntervalSeconds($entitlement->plan),
            'rollupRetentionDays' => $this->limits->rollupRetentionDays($entitlement->plan),
            'generatedAt' => $now->toIso8601String(),
        ];
    }
}
