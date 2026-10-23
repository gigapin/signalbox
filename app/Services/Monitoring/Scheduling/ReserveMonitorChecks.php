<?php

declare(strict_types=1);

/** Select bounded due work and atomically reserve regional executions with cadence advancement. */

namespace App\Services\Monitoring\Scheduling;

use App\Models\CheckExecution;
use App\Models\Monitor;
use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Global discovery reads only identities; all private work runs inside its owning tenant. */
final readonly class ReserveMonitorChecks
{
    /**
     * @param TenantContext $context Bounded tenant lifetime.
     * @param MonitorSlots $slots Deterministic UTC phase calculation.
     * @param ExecutionEligibility $eligibility Shared lock-aware admission rules.
     */
    public function __construct(private TenantContext $context, private MonitorSlots $slots, private ExecutionEligibility $eligibility)
    {
    }

    /** @return int Number of regional executions reserved; disabled execution performs no database access. */
    public function run(): int
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return 0;
        }

        $count = 0;
        $cutoff = CarbonImmutable::now('UTC');
        Monitor::withoutGlobalScope(TeamScope::class)
            ->select(['id', 'team_id'])
            ->where('is_paused', false)
            ->where('is_plan_suspended', false)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', $cutoff);
            })
            ->chunkById((int) config('monitor-scheduling.chunk_size'), function ($monitors) use (&$count): void {
                foreach ($monitors as $monitor) {
                    $count += $this->reserve($monitor->team_id, $monitor->id);
                }
            });

        return $count;
    }

    /**
     * @param int $teamId Discovered owner, revalidated inside the transaction.
     * @param int $monitorId Candidate identity.
     * @return int Regional reservation count; initialization creates no execution.
     */
    private function reserve(int $teamId, int $monitorId): int
    {
        return $this->context->run($teamId, fn (): int => DB::transaction(function () use ($teamId, $monitorId): int {
            $team = Team::query()->lockForUpdate()->find($teamId);
            $monitor = Monitor::query()->where('team_id', $teamId)->lockForUpdate()->find($monitorId);
            $now = CarbonImmutable::now('UTC');

            if ($team === null || $monitor === null || !$this->eligibility->allows($team, $monitor, $now)) {
                return 0;
            }

            if ($monitor->next_check_at !== null && $monitor->next_check_at->isAfter($now)) {
                return 0;
            }

            $initialized = $monitor->next_check_at !== null;
            $next = $this->slots->next($monitor->id, $monitor->interval_seconds, $now);
            $monitor->next_check_at = $next;
            // A scoped bulk write avoids decrypting configuration or emitting unrelated model events.
            Monitor::query()->whereKey($monitor->id)->update(['next_check_at' => $next]);

            if (!$initialized) {
                return 0;
            }

            $slot = $this->slots->latest($monitor->id, $monitor->interval_seconds, $now);

            foreach ($monitor->regions_json->values as $region) {
                $execution = new CheckExecution();
                $execution->forceFill([
                    'team_id' => $teamId,
                    'monitor_id' => $monitor->id,
                    'region' => $region->value,
                    'slot_at' => $slot,
                    'expires_at' => $next,
                    'monitor_revision' => $monitor->execution_revision,
                    'state' => 'pending',
                    'available_at' => $now,
                ])->save();
            }

            return count($monitor->regions_json->values);
        }));
    }
}
