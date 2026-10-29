<?php

declare(strict_types=1);

/** Collect the time a monitor is excluded from SLA: pauses, plan suspensions and maintenance windows. */

namespace App\Services\Metrics;

use App\Data\Metrics\TimeInterval;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\MonitorPausePeriod;
use Carbon\CarbonImmutable;

/** Reads only the monitor's own history through the tenant scope (ADR-009). */
final class ExclusionPeriods
{
    /**
     * Periods still active are treated as ending at the requested end.
     *
     * @param Monitor $monitor Monitor of the active team.
     * @param TimeInterval $window Requested half-open UTC window.
     * @return list<TimeInterval> Merged excluded intervals clipped to the window.
     */
    public function between(Monitor $monitor, TimeInterval $window): array
    {
        $intervals = [];

        $pauses = MonitorPausePeriod::query()
            ->where('monitor_id', $monitor->id)
            ->where('started_at', '<', $window->end)
            ->where(static fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $window->start))
            ->get();

        foreach ($pauses as $pause) {
            $intervals[] = $this->clipped($pause->started_at, $pause->ended_at ?? $window->end, $window);
        }

        $maintenance = MaintenanceWindow::query()
            ->where('monitor_id', $monitor->id)
            ->where('starts_at', '<', $window->end)
            ->where('ends_at', '>', $window->start)
            ->get();

        foreach ($maintenance as $planned) {
            $intervals[] = $this->clipped($planned->starts_at, $planned->ends_at, $window);
        }

        return TimeInterval::union(array_values(array_filter($intervals)));
    }

    /**
     * @param CarbonImmutable $start Recorded start.
     * @param CarbonImmutable $end Recorded or assumed end.
     * @param TimeInterval $window Requested window.
     * @return TimeInterval|null Part inside the window; null for zero-length or disjoint periods.
     */
    private function clipped(CarbonImmutable $start, CarbonImmutable $end, TimeInterval $window): ?TimeInterval
    {
        if (!$end->isAfter($start)) {
            return null;
        }

        return (new TimeInterval($start, $end))->clip($window);
    }
}
