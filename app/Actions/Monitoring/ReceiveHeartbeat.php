<?php

declare(strict_types=1);

/** Resolve only a dedicated bearer credential, then record a server receipt inside its locked tenant. */

namespace App\Actions\Monitoring;

use App\Enums\Monitoring\MonitorType;
use App\Exceptions\HeartbeatRateLimited;
use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use App\Models\Team;
use App\Services\Monitoring\HeartbeatLimits;
use App\Services\Monitoring\Scheduling\ExecutionEligibility;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** The restricted owner lookup never returns configuration; ownership and token validity are rechecked after locks. */
final readonly class ReceiveHeartbeat
{
    /**
     * @param TenantContext $tenants Bounded tenant lifetime derived exclusively from the validated digest.
     * @param ExecutionEligibility $eligibility Shared plan/quota/cadence/pause admission.
     * @param HeartbeatLimits $limits Mandatory per-credential traffic bound.
     */
    public function __construct(private TenantContext $tenants, private ExecutionEligibility $eligibility, private HeartbeatLimits $limits)
    {
    }

    /**
     * @param string|null $token Dedicated 64-character hexadecimal secret from Authorization only.
     * @return bool Whether the credential is valid; inactive monitors deliberately succeed without recording.
     * @throws HeartbeatRateLimited When a valid credential exceeds its configured request limit.
     */
    public function receive(#[\SensitiveParameter] ?string $token): bool
    {
        if ($token === null || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            return false;
        }

        $hash = hash('sha256', $token);
        // This is the single public discovery query: only owner identities are selected before entering TeamScope.
        $owner = DB::table('monitor_heartbeats')->where('token_hash', $hash)->first(['id', 'team_id', 'monitor_id']);

        if ($owner === null) {
            return false;
        }

        $teamId = (int) $owner->team_id;
        $monitorId = (int) $owner->monitor_id;

        return $this->tenants->run($teamId, fn (): bool => DB::transaction(function () use ($teamId, $monitorId, $hash): bool {
            $team = Team::query()->lockForUpdate()->find($teamId);
            $monitor = Monitor::query()->where('team_id', $teamId)->lockForUpdate()->find($monitorId);
            $state = MonitorHeartbeat::query()->where('monitor_id', $monitorId)->lockForUpdate()->first();

            if ($team === null || $monitor === null || $monitor->type !== MonitorType::Heartbeat
                || $state === null || $state->token_hash === null || !hash_equals($state->token_hash, $hash)) {
                return false;
            }

            $key = 'heartbeat:credential:'.$hash;

            if (RateLimiter::tooManyAttempts($key, $this->limits->get('requests_per_credential_per_minute'))) {
                throw new HeartbeatRateLimited(max(1, RateLimiter::availableIn($key)));
            }

            RateLimiter::hit($key, 60);
            $now = CarbonImmutable::now('UTC');

            if (!$this->eligibility->allows($team, $monitor, $now)) {
                return true;
            }

            // Preserve monotonic receipt state if the server wall clock moves backwards between requests.
            $receivedAt = $now->max($state->last_received_at ?? $state->started_at)->utc();
            DB::table('heartbeat_receipts')->insertOrIgnore([
                'team_id' => $teamId,
                'monitor_heartbeat_id' => $state->id,
                'generation' => $state->generation,
                'received_at' => $receivedAt->format('Y-m-d H:i:s.uP'),
            ]);
            $state->forceFill(['last_received_at' => $receivedAt])->save();

            return true;
        }));
    }
}
