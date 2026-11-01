<?php

declare(strict_types=1);

/** Gather a team's monthly report from the single SLA authority, adding no availability rule of its own. */

namespace App\Services\Reports;

use App\Data\Reports\MonthlyReport;
use App\Data\Reports\MonthlyReportRow;
use App\Data\Reports\ReportMonth;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Metrics\MonitorAvailability;
use Carbon\CarbonImmutable;

/**
 * Runs inside the route team's context. Only names and metrics are read, never monitor configuration. Monitors deleted since
 * the month do not appear: deleting a monitor deletes its history.
 */
final readonly class BuildMonthlyReport
{
    /** @param MonitorAvailability $availability Authority for uptime, coverage and SLA. */
    public function __construct(private MonitorAvailability $availability)
    {
    }

    /**
     * @param Team $team Authorized team, also the active tenant.
     * @param ReportMonth $month Month in the team's timezone.
     * @return MonthlyReport Report of all the team's monitors.
     */
    public function build(Team $team, ReportMonth $month): MonthlyReport
    {
        $window = $month->window();
        $incidents = $this->incidentsPerMonitor($team->id, $window->start, $window->end);
        $rows = [];

        $monitors = Monitor::query()
            ->where('team_id', $team->id)
            ->select(['id', 'team_id', 'name', 'created_at'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($monitors as $monitor) {
            $rows[] = new MonthlyReportRow(
                $monitor->name,
                $this->availability->between($monitor, $window),
                $incidents[$monitor->id] ?? 0,
            );
        }

        return new MonthlyReport($team->name, $month, CarbonImmutable::now('UTC'), $rows);
    }

    /**
     * @param int $teamId Owning team.
     * @param CarbonImmutable $start Inclusive window start.
     * @param CarbonImmutable $end Exclusive window end.
     * @return array<int, int> Incidents that started in the window, per monitor.
     */
    private function incidentsPerMonitor(int $teamId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = [];

        $rows = Incident::query()
            ->where('team_id', $teamId)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->selectRaw('monitor_id, count(*) AS total')
            ->groupBy('monitor_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->monitor_id] = (int) $row->total;
        }

        return $counts;
    }
}
