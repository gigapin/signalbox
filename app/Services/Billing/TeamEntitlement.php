<?php

declare(strict_types=1);

/** Resolve the plan a team is entitled to at a given instant. */

namespace App\Services\Billing;

use App\Enums\Billing\Plan;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Until Stripe subscriptions exist (M9), a stored Pro or Business plan stands for a paid entitlement.
 * A Free team receives Pro while its single initial trial lasts; the trial never lowers a higher plan.
 */
final readonly class TeamEntitlement
{
    /**
     * @param Team $team Team whose stored plan and trial boundary are current, ideally read under its lock.
     * @param CarbonImmutable|null $at Evaluation instant; defaults to the application clock.
     * @return Plan Effective plan; the exclusive trial end no longer grants Pro.
     */
    public function effectivePlan(Team $team, ?CarbonImmutable $at = null): Plan
    {
        if ($team->plan !== Plan::Free) {
            return $team->plan;
        }

        $at ??= CarbonImmutable::now();

        return $at->lessThan($team->trial_ends_at) ? Plan::Pro : Plan::Free;
    }
}
