<?php

declare(strict_types=1);

/** Advance regional failure sequences from each recorded result and apply the resulting slot verdict. */

namespace App\Services\Incidents;

use App\Contracts\Monitoring\ResultObserver;
use App\Data\Incidents\RegionalSequence;
use App\Data\Monitoring\RecordedResult;
use App\Models\Monitor;
use App\Models\MonitorRegionSequence;
use App\Services\Monitoring\Scheduling\MonitorSlots;

/**
 * Runs in the terminal result transaction, so the monitor lock serializes every region of the same monitor and
 * the incident change commits or rolls back together with the result.
 */
final readonly class IncidentDetection implements ResultObserver
{
    /**
     * @param MonitorSlots $slots Cadence used to recognize the immediately preceding slot.
     * @param ConfirmationRule $rule Pure joint evaluation.
     * @param IncidentAutomation $automation Incident changes for a verdict.
     */
    public function __construct(
        private MonitorSlots $slots,
        private ConfirmationRule $rule,
        private IncidentAutomation $automation,
    ) {
    }

    /** @param RecordedResult $result Result just written for the active tenant. */
    public function recorded(RecordedResult $result): void
    {
        $monitor = Monitor::query()->find($result->monitorId);

        // Results of a superseded revision remain history and never touch current sequences or incidents.
        if ($monitor === null || $monitor->execution_revision !== $result->monitorRevision) {
            return;
        }

        $stored = MonitorRegionSequence::query()
            ->where('monitor_id', $monitor->id)
            ->get()
            ->keyBy(static fn (MonitorRegionSequence $row): string => $row->region->value);
        $row = $stored->get($result->region->value) ?? new MonitorRegionSequence();
        $current = $row->exists ? $row->toSequence() : RegionalSequence::fresh($result->region, $result->monitorRevision);
        $previousSlot = $this->slots->latest($monitor->id, $monitor->interval_seconds, $result->slotAt->subSecond());
        $advanced = $current->advance($result, $previousSlot);

        if ($advanced === null) {
            return;
        }

        $row->forceFill(['monitor_id' => $monitor->id])->store($advanced);
        $stored->put($result->region->value, $row);

        $sequences = $stored->map(static fn (MonitorRegionSequence $sequence): RegionalSequence => $sequence->toSequence())->all();
        $regions = $monitor->regions_json->values;
        $progress = $this->rule->progress($regions, $sequences, $monitor->execution_revision);

        if ($progress !== $monitor->consecutive_failures) {
            Monitor::query()->whereKey($monitor->id)->update(['consecutive_failures' => $progress]);
        }

        $verdict = $this->rule->verdict($regions, $sequences, $monitor->confirmation_threshold, $monitor->execution_revision, $result->slotAt);
        $this->automation->apply($monitor, $verdict, $result->slotAt, $result->recordedAt);
    }
}
