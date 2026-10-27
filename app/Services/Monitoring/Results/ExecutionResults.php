<?php

declare(strict_types=1);

/** Append the immutable terminal result and update only a current-revision, non-regressing monitor summary. */

namespace App\Services\Monitoring\Results;

use App\Data\Monitoring\Checks\CheckOutcome;
use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\Monitoring\MonitorType;
use App\Models\CheckExecution;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Call only inside the ledger's Team → Monitor → execution transaction, after partition preparation. */
final readonly class ExecutionResults
{
    /** @param TenantContext $tenants Active private write boundary. */
    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * @param CheckExecution $work Locked execution after its fenced terminal transition.
     * @param CheckOutcome|null $outcome Genuine target observation; absent for every non-observation path.
     * @param CarbonImmutable $recordedAt UTC transaction completion reference.
     * @throws InvalidArgumentException When identity, terminal state or observation correlation is inconsistent.
     */
    public function record(CheckExecution $work, ?CheckOutcome $outcome, CarbonImmutable $recordedAt): void
    {
        if ($this->tenants->requireTeamId() !== $work->team_id || !in_array($work->state, ['completed', 'missed', 'cancelled', 'infrastructure_failed'], true)) {
            throw new InvalidArgumentException('Result recording requires a local terminal execution.');
        }

        if ($outcome !== null && ($work->state !== 'completed' || $outcome->context->executionId !== $work->id
            || $outcome->context->teamId !== $work->team_id || $outcome->context->monitorId !== $work->monitor_id
            || $outcome->context->region->value !== $work->region || !$outcome->context->referenceAt->equalTo($work->slot_at))) {
            throw new InvalidArgumentException('The checker outcome does not match its acquired execution.');
        }

        $kind = $outcome !== null ? CheckResultKind::Observed : match ($work->state) {
            'missed' => CheckResultKind::Missed,
            'cancelled' => CheckResultKind::Cancelled,
            default => CheckResultKind::Infrastructure,
        };
        $code = $outcome === null ? $kind->value : $outcome->error?->value;
        $message = $outcome?->errorMessage() ?? match ($kind) {
            CheckResultKind::Observed => null,
            CheckResultKind::Missed => 'The execution slot expired before acquisition.',
            CheckResultKind::Cancelled => 'The execution was no longer eligible.',
            CheckResultKind::Infrastructure => 'The worker did not durably complete an observation.',
        };
        DB::table('check_results')->insert([
            'team_id' => $work->team_id,
            'execution_id' => $work->id,
            'monitor_id' => $work->monitor_id,
            'region' => $work->region,
            'slot_at' => $work->slot_at->utc()->format('Y-m-d H:i:sP'),
            'monitor_revision' => $work->monitor_revision,
            'kind' => $kind->value,
            'status' => ($outcome === null ? MonitorStatus::Unknown : $outcome->status)->value,
            'checked_at' => $outcome?->observedAt->utc()->format('Y-m-d H:i:s.uP'),
            'recorded_at' => $recordedAt->utc()->format('Y-m-d H:i:s.uP'),
            'response_time_ms' => $outcome?->latencyMs === null ? null : (int) round($outcome->latencyMs, 0, PHP_ROUND_HALF_UP),
            'status_code' => $outcome?->statusCode,
            'error_code' => $code,
            'error_message' => $message,
            'tls_expires_at' => $outcome?->tlsExpiresAt?->utc()->format('Y-m-d H:i:sP'),
        ]);
        $this->summarize($work);
    }

    /**
     * @param CheckExecution $work Execution whose new result may advance or complete the latest regional slot.
     */
    private function summarize(CheckExecution $work): void
    {
        $monitor = Monitor::query()->find($work->monitor_id);

        if ($monitor === null || $monitor->execution_revision !== $work->monitor_revision
            || $monitor->last_result_slot_at?->isAfter($work->slot_at)) {
            return;
        }

        $regions = $monitor->regions_json->toValues();
        $results = CheckResult::query()->where('monitor_id', $monitor->id)
            ->where('slot_at', $work->slot_at)->where('monitor_revision', $monitor->execution_revision)
            ->whereIn('region', $regions)->get();
        $status = MonitorStatus::Unknown;

        if ($results->count() === count($regions) && $results->every(static fn (CheckResult $result): bool => $result->kind === CheckResultKind::Observed)) {
            $statuses = $results->map(static fn (CheckResult $result): string => $result->status->value)->unique();

            if ($statuses->count() === 1) {
                $status = MonitorStatus::from($statuses->firstOrFail());
            }
        }

        $observed = $results->pluck('checked_at')->filter()->sort()->last();
        $checked = $monitor->last_checked_at;

        if ($observed instanceof CarbonImmutable && ($checked === null || $observed->isAfter($checked))) {
            $checked = $observed;
        }

        $summary = [
            'last_status' => $status->value,
            'last_result_slot_at' => $work->slot_at,
            'last_checked_at' => $checked?->utc()->format('Y-m-d H:i:sP'),
        ];
        $expires = $monitor->type === MonitorType::Tls ? $results->pluck('tls_expires_at')->filter()->sort()->first() : null;

        // A slot without a certificate reading keeps the last observed expiry; definition changes reset it in ManageMonitors.
        if ($expires instanceof CarbonImmutable) {
            $summary['ssl_expires_at'] = $expires->utc()->format('Y-m-d H:i:sP');
        }

        Monitor::query()->whereKey($monitor->id)->update($summary);
    }
}
