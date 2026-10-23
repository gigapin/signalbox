<?php

declare(strict_types=1);

/** Resolve the plan a team is entitled to at a given instant. */

namespace App\Services\Billing;

use App\Data\Billing\Entitlement;
use App\Enums\Billing\EntitlementSource;
use App\Enums\Billing\Plan;
use App\Enums\Billing\PlanOverrideKind;
use App\Models\Team;
use App\Models\TeamPlanOverride;
use Carbon\CarbonImmutable;

/**
 * Precedence, highest first:
 * 1. an active operator override: unlimited yields the finite Internal profile; a comped plan yields the
 *    higher of the granted plan and the stored paid plan, so a gift never lowers what a team pays for;
 * 2. a stored Pro or Business plan, standing for a paid subscription until Stripe arrives in M9;
 * 3. the single initial trial of a Free team, granting Pro;
 * 4. Free.
 * Override and trial ends are exclusive. Callers mutating quota hold the Team lock, which override
 * changes also take, so the override read here cannot change before their write commits.
 */
final readonly class TeamEntitlement
{
    /** @var list<Plan> RANK Sellable plans from lowest to highest, used to compare a gift with a paid plan. */
    private const array RANK = [Plan::Free, Plan::Pro, Plan::Business];

    /**
     * @param Team $team Team whose stored plan and trial boundary are current, ideally read under its lock.
     * @param CarbonImmutable|null $at Evaluation instant; defaults to the application clock.
     * @return Plan Effective plan whose limits apply.
     */
    public function effectivePlan(Team $team, ?CarbonImmutable $at = null): Plan
    {
        return $this->resolve($team, $at)->plan;
    }

    /**
     * @param Team $team Team to evaluate; its override is read afresh, never from a loaded relation.
     * @param CarbonImmutable|null $at Evaluation instant; defaults to the application clock.
     * @return Entitlement Effective plan, the rule that produced it and that rule's exclusive end.
     */
    public function resolve(Team $team, ?CarbonImmutable $at = null): Entitlement
    {
        $at ??= CarbonImmutable::now();
        $override = TeamPlanOverride::query()->where('team_id', $team->id)->first();

        if ($override !== null && $override->isActiveAt($at)) {
            return $this->fromOverride($team, $override);
        }

        return $this->fromSubscriptionOrTrial($team, $at);
    }

    /**
     * @param Team $team Team carrying the override.
     * @param TeamPlanOverride $override Active override.
     * @return Entitlement Internal profile, or the higher of the gift and the stored paid plan.
     */
    private function fromOverride(Team $team, TeamPlanOverride $override): Entitlement
    {
        if ($override->kind === PlanOverrideKind::Unlimited) {
            return new Entitlement(Plan::Internal, EntitlementSource::UnlimitedOverride, $override->expires_at);
        }

        // The kind/plan check constraint guarantees a granted plan for comped overrides.
        $granted = $override->plan ?? Plan::Pro;

        if (array_search($team->plan, self::RANK, true) > array_search($granted, self::RANK, true)) {
            return new Entitlement($team->plan, EntitlementSource::Subscription, null);
        }

        return new Entitlement($granted, EntitlementSource::CompedOverride, $override->expires_at);
    }

    /**
     * @param Team $team Team without an active override.
     * @param CarbonImmutable $at Evaluation instant.
     * @return Entitlement Stored paid plan, trial Pro or Free.
     */
    private function fromSubscriptionOrTrial(Team $team, CarbonImmutable $at): Entitlement
    {
        if ($team->plan !== Plan::Free) {
            return new Entitlement($team->plan, EntitlementSource::Subscription, null);
        }

        if ($at->lessThan($team->trial_ends_at)) {
            return new Entitlement(Plan::Pro, EntitlementSource::Trial, $team->trial_ends_at);
        }

        return new Entitlement(Plan::Free, EntitlementSource::Free, null);
    }
}
