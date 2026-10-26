<?php

declare(strict_types=1);

/** Issue or revoke dedicated heartbeat secrets under fresh tenant policy and lifecycle locks. */

namespace App\Actions\Monitoring;

use App\Enums\Monitoring\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Plaintext is returned only at issuance; rotation neither rearms nor repairs an overdue monitor. */
final readonly class ManageHeartbeatCredentials
{
    /** @param TenantContext $tenants Bounded owner context for every authorized mutation. */
    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * Current-policy and missing-record failures propagate from the locked transaction callback.
     *
     * @param User $actor Verified owner/admin/member managing this monitor.
     * @param int $teamId Explicit authorized route team.
     * @param int $monitorId Tenant-owned heartbeat monitor.
     * @return string New 256-bit hexadecimal bearer token, never stored or flashed in plaintext.
     */
    public function rotate(User $actor, int $teamId, int $monitorId): string
    {
        return $this->locked($actor, $teamId, $monitorId, static function (MonitorHeartbeat $state): string {
            $token = bin2hex(random_bytes(32));
            $state->forceFill(['token_hash' => hash('sha256', $token)])->save();

            return $token;
        });
    }

    /**
     * Current-policy and missing-record failures propagate from the locked transaction callback.
     *
     * @param User $actor Verified manager performing immediate credential revocation.
     * @param int $teamId Explicit authorized route team.
     * @param int $monitorId Tenant-owned heartbeat monitor.
     */
    public function revoke(User $actor, int $teamId, int $monitorId): void
    {
        $this->locked($actor, $teamId, $monitorId, static function (MonitorHeartbeat $state): void {
            $state->forceFill(['token_hash' => null])->save();
        });
    }

    /**
     * Policy, type and existence checks may reject inside the transaction, which always restores tenant context.
     *
     * @template TResult
     * @param User $actor Actor whose current policy is rechecked after locking.
     * @param int $teamId Owning team, locked before the monitor.
     * @param int $monitorId Tenant-owned monitor.
     * @param Closure(MonitorHeartbeat): TResult $operation Mutation receiving the locked private state.
     * @return TResult Result after commit, with no credential in session state.
     */
    private function locked(User $actor, int $teamId, int $monitorId, Closure $operation): mixed
    {
        return $this->tenants->run($teamId, fn (): mixed => DB::transaction(function () use ($actor, $teamId, $monitorId, $operation): mixed {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $monitor = Monitor::query()->where('team_id', $teamId)->lockForUpdate()->findOrFail($monitorId);
            Gate::forUser($actor)->authorize('update', $monitor);
            abort_unless($monitor->type === MonitorType::Heartbeat, 404);
            $state = MonitorHeartbeat::query()->where('monitor_id', $monitorId)->lockForUpdate()->firstOrFail();

            return $operation($state);
        }));
    }
}
