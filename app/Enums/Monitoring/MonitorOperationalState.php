<?php

declare(strict_types=1);

/** Summarize whether a monitor is eligible for future scheduling, independently of its observed status. */

namespace App\Enums\Monitoring;

use App\Models\Monitor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Derived from the independent `is_paused` and `is_plan_suspended` flags. Plan suspension takes precedence:
 * a suspended monitor is reported as suspended even when it is also voluntarily paused, because resuming
 * it voluntarily would not make it run.
 */
enum MonitorOperationalState: string
{
    /** Neither paused nor suspended by plan reconciliation. */
    case Active = 'active';

    /** Voluntarily paused and not suspended by the plan. */
    case Paused = 'paused';

    /** Suspended by plan reconciliation, regardless of the voluntary pause flag. */
    case Suspended = 'suspended';

    /**
     * @param Monitor $monitor Monitor whose persisted flags are summarized.
     * @return self Single displayable state following the documented precedence.
     */
    public static function of(Monitor $monitor): self
    {
        if ($monitor->is_plan_suspended) {
            return self::Suspended;
        }

        if ($monitor->is_paused) {
            return self::Paused;
        }

        return self::Active;
    }

    /**
     * Restrict a query to monitors in this state using the same precedence as of().
     *
     * @param Builder<Monitor> $query Monitor query that already carries tenant constraints.
     */
    public function constrain(Builder $query): void
    {
        match ($this) {
            self::Active => $query->where('is_plan_suspended', false)->where('is_paused', false),
            self::Paused => $query->where('is_plan_suspended', false)->where('is_paused', true),
            self::Suspended => $query->where('is_plan_suspended', true),
        };
    }
}
