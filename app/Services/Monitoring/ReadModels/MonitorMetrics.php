<?php

declare(strict_types=1);

/** Compose private metric contracts from the existing time-weighted availability authority. */

namespace App\Services\Monitoring\ReadModels;

use App\Data\Metrics\AvailabilityReport;
use App\Data\Metrics\TimeInterval;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use App\Services\Metrics\MonitorAvailability;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/** Recent bars preserve all four duration categories; missing/partial coverage never becomes a green hour. */
final readonly class MonitorMetrics
{
    /**
     * @param MonitorAvailability $availability Time-weighted SLA, including rollups and retained raw edges.
     * @param MonitorMetricLatency $latency Exact observed regional statistics.
     * @param TeamEntitlement $entitlement Current plan, including overrides/expiry.
     * @param PlanLimits $limits Authoritative readable history.
     * @param TenantContext $tenant Fail-closed context for direct service consumers.
     */
    public function __construct(
        private MonitorAvailability $availability,
        private MonitorMetricLatency $latency,
        private TeamEntitlement $entitlement,
        private PlanLimits $limits,
        private TenantContext $tenant,
    ) {
    }

    /**
     * @param Monitor $monitor Authorized route monitor; this reader never mutates state.
     * @param Team $team Authorized route team matching the monitor.
     * @param string $period Validated 24h/7d/30d period.
     * @param string $region Validated all/eu/us latency region; availability always uses agreed regional verdicts.
     * @return array<string, mixed> Period totals, 24 recent duration bars and selected latency, with explicit retention.
     */
    public function read(Monitor $monitor, Team $team, string $period, string $region): array
    {
        abort_unless($monitor->team_id === $team->id && $this->tenant->requireTeamId() === $team->id, 404);
        $now = CarbonImmutable::now('UTC');
        $history = $this->limits->rollupRetentionDays($this->entitlement->effectivePlan($team));
        $recent = new TimeInterval($now->subDay(), $now);
        $hours = $this->availability->hourly($monitor, $recent);
        $periods = [];
        foreach (['24h' => 1, '7d' => 7, '30d' => 30] as $key => $days) {
            $window = new TimeInterval($now->subDays($days), $now);
            $report = $key === '24h' ? ($hours === [] ? AvailabilityReport::unavailable() : AvailabilityReport::combined($hours)) : $this->availability->between($monitor, $window);
            $periods[$key] = [...$this->report($report), 'startsAt' => $window->start->toISOString(), 'endsAt' => $now->toISOString()];
        }
        $bars = [];
        foreach ($hours as $index => $report) {
            $bars[] = [...$this->report($report), 'startsAt' => $recent->start->addHours($index)->toISOString(), 'endsAt' => $recent->start->addHours($index + 1)->toISOString()];
        }
        $days = match ($period) {
            '7d' => 7, '30d' => 30, default => 1
        };
        $window = new TimeInterval($now->subDays($days), $now);
        $permitted = !$window->start->max($monitor->created_at ?? $window->start)->isBefore($now->subDays($history));

        return [
            'generatedAt' => $now->toISOString(),
            'timezone' => 'UTC',
            'historyDays' => $history,
            'selection' => ['period' => $period, 'region' => $region],
            'periods' => $periods,
            'recent' => $bars,
            'latency' => $permitted
                ? $this->latency->read($monitor, $window, $region, $days === 1 ? 3600 : 86400)
                : ['available' => false, 'source' => 'raw', 'summary' => null, 'series' => []],
        ];
    }

    /**
     * @param AvailabilityReport $report Authoritative durations in integer microseconds.
     * @return array<string, mixed> Durations in seconds (fractional microseconds preserved), ratios and explicit unavailable state.
     */
    private function report(AvailabilityReport $report): array
    {
        $d = $report->durations;

        return [
            'available' => $d !== null,
            'observedPercent' => $report->observedPercent(),
            'coveragePercent' => $report->coveragePercent(),
            'slaPercent' => $report->slaPercent(),
            'durations' => $d === null ? null : [
                'upSeconds' => $d->up / 1_000_000,
                'downSeconds' => $d->down / 1_000_000,
                'unknownSeconds' => $d->unknown / 1_000_000,
                'excludedSeconds' => $d->excluded / 1_000_000,
            ],
        ];
    }
}
