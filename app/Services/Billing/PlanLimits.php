<?php

declare(strict_types=1);

/** Read plan entitlements from the single versioned plan definition. */

namespace App\Services\Billing;

use App\Enums\Billing\Plan;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/** Values are returned only when present and positive; nothing is treated as unlimited by omission. */
final readonly class PlanLimits
{
    /** @param Repository $config Application configuration containing the `plans` matrix. */
    public function __construct(private Repository $config)
    {
    }

    /**
     * @param Plan $plan Effective plan, normally resolved by TeamEntitlement.
     * @return int Maximum monitors a team may hold, paused and plan-suspended monitors included.
     */
    public function monitors(Plan $plan): int
    {
        return $this->get($plan, 'monitors');
    }

    /**
     * @param Plan $plan Effective plan.
     * @return int Shortest allowed monitor interval in seconds.
     */
    public function minimumIntervalSeconds(Plan $plan): int
    {
        return $this->get($plan, 'min_interval_seconds');
    }

    /**
     * @param Plan $plan Effective plan.
     * @param string $name Entitlement key inside the plan entry.
     * @return int Configured positive value.
     * @throws LogicException When the plan or entitlement is missing or not a positive integer.
     */
    private function get(Plan $plan, string $name): int
    {
        $value = $this->config->get('plans.'.$plan->value.'.'.$name);

        if (!is_int($value) || $value < 1) {
            throw new LogicException('Plan entitlements require a positive integer for every plan.');
        }

        return $value;
    }
}
