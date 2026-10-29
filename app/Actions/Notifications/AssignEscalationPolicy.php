<?php

declare(strict_types=1);

/** Choose which escalation policy a monitor uses instead of the team default. */

namespace App\Actions\Notifications;

use App\Exceptions\EscalationPolicyRejected;
use App\Models\EscalationPolicy;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Choosing among existing policies is monitor configuration, authorized by MonitorPolicy::update; creating or
 * changing the policies themselves stays with owners and admins. The Team lock orders this against policy
 * deletion, and the change never touches the monitor's execution state.
 */
final readonly class AssignEscalationPolicy
{
    /** @param TenantContext $context Explicit tenant boundary for the change. */
    public function __construct(private TenantContext $context)
    {
    }

    /**
     * @param User $actor Manager of the monitor.
     * @param int $teamId Active team.
     * @param int $monitorId Monitor of that team.
     * @param int|null $policyId Policy of that team, or null to follow the team default.
     * @return Monitor Monitor with the override saved; a policy outside the team raises EscalationPolicyRejected under the lock.
     */
    public function assign(User $actor, int $teamId, int $monitorId, ?int $policyId): Monitor
    {
        return $this->context->run($teamId, static fn (): Monitor => DB::transaction(static function () use ($actor, $teamId, $monitorId, $policyId): Monitor {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $monitor = Monitor::query()->lockForUpdate()->findOrFail($monitorId);
            Gate::forUser($actor)->authorize('update', $monitor);

            if ($policyId !== null && !EscalationPolicy::query()->whereKey($policyId)->exists()) {
                throw new EscalationPolicyRejected('Choose an escalation policy of this team.');
            }

            $monitor->forceFill(['escalation_policy_id' => $policyId])->save();

            return $monitor;
        }));
    }
}
