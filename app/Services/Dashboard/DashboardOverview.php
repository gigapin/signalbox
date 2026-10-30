<?php

declare(strict_types=1);

/** Compose read-only team dashboard projections without loading monitor configuration. */

namespace App\Services\Dashboard;

use App\Data\Metrics\AvailabilityReport;
use App\Data\Metrics\TimeInterval;
use App\Http\Presenters\Incidents\IncidentPresenter;
use App\Http\Presenters\Monitoring\MonitorPresenter;
use App\Models\Incident;
use App\Models\Monitor;
use App\Services\Metrics\MonitorAvailability;
use App\Services\Monitoring\MonitorListing;
use Carbon\CarbonImmutable;

/** Separate operational summaries, historical measured latency and the time-weighted availability of the selected monitor. */
final readonly class DashboardOverview
{
    /** Maximum open incidents on the overview; the incident list provides the full history. */
    private const int INCIDENT_LIMIT = 5;

    /**
     * @param MonitorPresenter $monitorView Whitelisted monitor rows without configuration values.
     * @param IncidentPresenter $incidentView Whitelisted incident summaries without private timeline or cause notes.
     * @param DashboardLatency $latency Bounded raw-measurement query, separate from time-weighted SLA rules.
     * @param MonitorAvailability $availability Authoritative time-weighted uptime, coverage and SLA.
     */
    public function __construct(
        private MonitorPresenter $monitorView,
        private IncidentPresenter $incidentView,
        private DashboardLatency $latency,
        private MonitorAvailability $availability,
    ) {
    }

    /**
     * @param int $teamId Authorized route team; every aggregate also carries this explicit predicate.
     * @param int|null $monitorId Explicit same-team chart selection, or null to choose the first visible row.
     * @param int $page One-based monitor page.
     * @return array<string, mixed> Operational counts, bounded lists, selected identity, UTC window and nullable uptime contract.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When an explicit monitor does not belong to this team.
     */
    public function read(int $teamId, ?int $monitorId, int $page): array
    {
        $now = CarbonImmutable::now('UTC');
        $monitors = Monitor::query()->where('team_id', $teamId)
            ->select(['id', 'team_id', 'name', 'type', 'last_status', 'is_paused', 'is_plan_suspended', 'interval_seconds', 'regions_json', 'last_checked_at', 'created_at'])
            ->orderBy('name')->orderBy('id')
            ->paginate(MonitorListing::PER_PAGE, ['*'], 'page', $page);
        $selected = $monitorId === null ? ($monitors->items()[0] ?? null) : Monitor::query()
            ->where('team_id', $teamId)
            ->select(['id', 'team_id', 'name', 'created_at'])
            ->findOrFail($monitorId);
        $open = Incident::query()->where('team_id', $teamId)->whereNull('resolved_at');
        $openCount = (clone $open)->count();
        $incidents = [];

        foreach ($open->with('monitor:id,team_id,name')->orderByDesc('started_at')->orderByDesc('id')->limit(self::INCIDENT_LIMIT)->get() as $incident) {
            $incidents[] = $this->incidentView->summary($incident);
        }

        $latency = $this->latency->series($teamId, $selected?->id, $now);
        $window = new TimeInterval($now->subHours(DashboardLatency::HOURS), $now);
        $hours = $selected === null ? [] : $this->availability->hourly($selected, $window);

        return [
            'generatedAt' => $now->format('Y-m-d\TH:i:s.u\Z'),
            'window' => ['startsAt' => $now->subHours(DashboardLatency::HOURS)->format('Y-m-d\TH:i:s.u\Z'), 'endsAt' => $now->format('Y-m-d\TH:i:s.u\Z')],
            'counts' => [...$this->counts($teamId), 'openIncidents' => $openCount],
            'monitors' => $this->monitorView->page($monitors),
            'incidents' => $incidents,
            'selectedMonitor' => $selected === null ? null : ['id' => $selected->id, 'name' => $selected->name],
            'latency' => $latency,
            'uptime' => $this->uptime($hours, $latency),
        ];
    }

    /**
     * @param list<AvailabilityReport> $hours Hourly availability of the selected monitor; empty when none is selected.
     * @param list<array{at: string, eu: array{meanMs: float|null, samples: int}, us: array{meanMs: float|null, samples: int}}> $latency Chart buckets sharing the same hours.
     * @return array{observedPercent: float|null, coveragePercent: float|null, slaPercent: float|null, durations: array{upSeconds: int, downSeconds: int, unknownSeconds: int, excludedSeconds: int}|null, series: list<array{at: string, value: float|null}>} Availability of the window; missing values stay null, never 0 or 100.
     */
    private function uptime(array $hours, array $latency): array
    {
        $total = $hours === [] ? AvailabilityReport::unavailable() : AvailabilityReport::combined($hours);
        $durations = $total->durations;
        $series = [];

        foreach ($latency as $index => $point) {
            $series[] = ['at' => $point['at'], 'value' => isset($hours[$index]) ? $hours[$index]->observedPercent() : null];
        }

        return [
            'observedPercent' => $total->observedPercent(),
            'coveragePercent' => $total->coveragePercent(),
            'slaPercent' => $total->slaPercent(),
            'durations' => $durations === null ? null : [
                'upSeconds' => intdiv($durations->up, 1_000_000),
                'downSeconds' => intdiv($durations->down, 1_000_000),
                'unknownSeconds' => intdiv($durations->unknown, 1_000_000),
                'excludedSeconds' => intdiv($durations->excluded, 1_000_000),
            ],
            'series' => $series,
        ];
    }

    /**
     * @param int $teamId Active team, explicit alongside the global scope.
     * @return array{total: int, up: int, down: int, unknown: int, paused: int, suspended: int} Disjoint counters; suspended takes precedence over paused, and only active monitors contribute observed status.
     */
    private function counts(int $teamId): array
    {
        $active = 'NOT is_paused AND NOT is_plan_suspended';
        $row = Monitor::query()->where('team_id', $teamId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE {$active} AND last_status = 'up') AS up")
            ->selectRaw("COUNT(*) FILTER (WHERE {$active} AND last_status = 'down') AS down")
            ->selectRaw("COUNT(*) FILTER (WHERE {$active} AND last_status = 'unknown') AS unknown")
            ->selectRaw('COUNT(*) FILTER (WHERE is_paused AND NOT is_plan_suspended) AS paused')
            ->selectRaw('COUNT(*) FILTER (WHERE is_plan_suspended) AS suspended')
            ->firstOrFail();

        return [
            'total' => (int) $row->getAttribute('total'),
            'up' => (int) $row->getAttribute('up'),
            'down' => (int) $row->getAttribute('down'),
            'unknown' => (int) $row->getAttribute('unknown'),
            'paused' => (int) $row->getAttribute('paused'),
            'suspended' => (int) $row->getAttribute('suspended'),
        ];
    }
}
