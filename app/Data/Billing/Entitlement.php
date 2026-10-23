<?php

declare(strict_types=1);

/** Carry the resolved entitlement of a team at one instant. */

namespace App\Data\Billing;

use App\Enums\Billing\EntitlementSource;
use App\Enums\Billing\Plan;
use Carbon\CarbonImmutable;

/** Immutable result of TeamEntitlement::resolve(); limits are read from PlanLimits with the plan. */
final readonly class Entitlement
{
    /**
     * @param Plan $plan Effective plan whose limits apply.
     * @param EntitlementSource $source Rule that produced the plan.
     * @param CarbonImmutable|null $expiresAt Exclusive end of the override or trial that granted it; null when open-ended.
     */
    public function __construct(
        public Plan $plan,
        public EntitlementSource $source,
        public ?CarbonImmutable $expiresAt,
    ) {
    }
}
