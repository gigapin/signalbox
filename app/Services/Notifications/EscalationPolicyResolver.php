<?php

declare(strict_types=1);

/** Decide which escalation policy applies to a monitor. */

namespace App\Services\Notifications;

use App\Models\EscalationPolicy;
use App\Models\Monitor;

/**
 * The monitor's override wins; otherwise the team default applies; with neither, the monitor notifies nobody
 * (user decision). Callers run inside the monitor's tenant context, so the scope keeps lookups in its team.
 */
final class EscalationPolicyResolver
{
    /**
     * @param Monitor $monitor Monitor of the active team.
     * @return EscalationPolicy|null Effective policy, or null when the monitor is not notified.
     */
    public function forMonitor(Monitor $monitor): ?EscalationPolicy
    {
        if ($monitor->escalation_policy_id !== null) {
            $override = EscalationPolicy::query()->find($monitor->escalation_policy_id);

            if ($override !== null) {
                return $override;
            }
        }

        return $this->teamDefault();
    }

    /** @return EscalationPolicy|null The active team's default policy, if one is marked. */
    public function teamDefault(): ?EscalationPolicy
    {
        return EscalationPolicy::query()->where('is_default', true)->first();
    }
}
