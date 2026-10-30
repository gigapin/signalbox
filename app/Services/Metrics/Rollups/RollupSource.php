<?php

declare(strict_types=1);

/** Read the raw ledger facts needed to calculate rollups; this is the restricted technical access of ADR-008. */

namespace App\Services\Metrics\Rollups;

use App\Data\Metrics\SlotRecord;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Every query names its team explicitly, and only timing and outcome columns are ever selected. */
final class RollupSource
{
    /**
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor being rolled up.
     * @param CarbonImmutable $start Inclusive range start.
     * @param CarbonImmutable $end Exclusive range end.
     * @param CarbonImmutable|null $settledAt When given, slots with work that can still finish at this instant are left out, because their regional verdict is not final; closed hours never need it.
     * @return list<SlotRecord> Slots of the range plus earlier slots whose validity reaches into it, ordered by slot.
     */
    public function slots(int $teamId, int $monitorId, CarbonImmutable $start, CarbonImmutable $end, ?CarbonImmutable $settledAt = null): array
    {
        $settled = $settledAt === null ? null : $this->instant($settledAt);

        $rows = DB::select(<<<'SQL'
            SELECT
                (EXTRACT(EPOCH FROM execution.slot_at) * 1000000)::bigint AS slot_at,
                (EXTRACT(EPOCH FROM execution.expires_at) * 1000000)::bigint AS next_slot_at,
                result.status,
                (EXTRACT(EPOCH FROM result.checked_at) * 1000000)::bigint AS checked_at,
                result.response_time_ms
            FROM check_executions execution
            LEFT JOIN check_results result
                ON result.team_id = execution.team_id
                AND result.execution_id = execution.id
                AND result.slot_at = execution.slot_at
                AND result.slot_at >= :result_from
                AND result.slot_at < :result_to
                AND result.kind = 'observed'
            WHERE execution.team_id = :team_id
                AND execution.monitor_id = :monitor_id
                AND execution.slot_at < :slot_to
                AND (
                    execution.slot_at >= :slot_from
                    OR (
                        execution.slot_at >= :lookback_from
                        AND execution.expires_at + (execution.expires_at - execution.slot_at) > :valid_after
                    )
                )
                AND (
                    :settled_check::timestamptz IS NULL
                    OR NOT EXISTS (
                        SELECT 1
                        FROM check_executions sibling
                        WHERE sibling.team_id = execution.team_id
                            AND sibling.monitor_id = execution.monitor_id
                            AND sibling.slot_at = execution.slot_at
                            AND (
                                (sibling.state = 'pending' AND sibling.expires_at > :settled_pending)
                                OR (sibling.state = 'running' AND sibling.deadline_at > :settled_running)
                            )
                    )
                )
            ORDER BY execution.slot_at
            SQL, [
            'result_from' => $this->instant($start->subSeconds($this->lookbackSeconds())),
            'result_to' => $this->instant($end),
            'team_id' => $teamId,
            'monitor_id' => $monitorId,
            'slot_to' => $this->instant($end),
            'slot_from' => $this->instant($start),
            'lookback_from' => $this->instant($start->subSeconds($this->lookbackSeconds())),
            'valid_after' => $this->instant($start),
            'settled_check' => $settled,
            'settled_pending' => $settled,
            'settled_running' => $settled,
        ]);

        return $this->grouped($rows);
    }

    /**
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor being rolled up.
     * @param CarbonImmutable $now Current instant.
     * @param int $marginSeconds Tolerance for transactions still committing around the current instant.
     * @return CarbonImmutable|null Earliest slot whose execution can still acquire or finish; null when none can.
     */
    public function oldestOpenSlot(int $teamId, int $monitorId, CarbonImmutable $now, int $marginSeconds): ?CarbonImmutable
    {
        $slot = DB::table('check_executions')
            ->where('team_id', $teamId)
            ->where('monitor_id', $monitorId)
            ->where(function ($query) use ($now, $marginSeconds): void {
                $threshold = $this->instant($now->subSeconds($marginSeconds));

                $query
                    ->where(static fn ($pending) => $pending->where('state', 'pending')->where('expires_at', '>', $threshold))
                    ->orWhere(static fn ($running) => $running->where('state', 'running')->where('deadline_at', '>', $threshold));
            })
            ->min('slot_at');

        return is_string($slot) ? CarbonImmutable::parse($slot)->utc() : null;
    }

    /** @return int Longest possible validity of a verdict: two periods of the largest configurable cadence. */
    public function lookbackSeconds(): int
    {
        return 2 * (int) config('monitor-input.interval_max_seconds');
    }

    /**
     * @param array<int, \stdClass> $rows One row per regional execution, ordered by slot.
     * @return list<SlotRecord> Rows gathered per slot.
     */
    private function grouped(array $rows): array
    {
        $slots = [];

        foreach ($rows as $row) {
            $slotAt = (int) $row->slot_at;

            $slots[$slotAt] ??= ['nextSlotAt' => (int) $row->next_slot_at, 'executions' => 0, 'observations' => []];
            ++$slots[$slotAt]['executions'];

            if ($row->status !== null) {
                $slots[$slotAt]['observations'][] = [
                    'status' => MonitorStatus::from((string) $row->status),
                    'checkedAt' => (int) $row->checked_at,
                    'latencyMs' => $row->response_time_ms === null ? null : (int) $row->response_time_ms,
                ];
            }
        }

        $records = [];

        foreach ($slots as $slotAt => $slot) {
            $records[] = new SlotRecord($slotAt, $slot['nextSlotAt'], $slot['executions'], $slot['observations']);
        }

        return $records;
    }

    /**
     * @param CarbonImmutable $instant Query boundary.
     * @return string UTC text that keeps the microseconds Laravel's default DateTime binding would drop.
     */
    private function instant(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
