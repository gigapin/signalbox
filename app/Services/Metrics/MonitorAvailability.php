<?php

declare(strict_types=1);

/** Calculate a monitor's time-weighted availability for any window, from rollups and retained raw results. */

namespace App\Services\Metrics;

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\AvailabilityReport;
use App\Data\Metrics\SlotRecord;
use App\Data\Metrics\TimeInterval;
use App\Models\CheckRollupHourly;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use App\Services\Metrics\Rollups\RollupSource;
use App\Services\Monitoring\Results\ResultPartitions;
use Carbon\CarbonImmutable;

/**
 * The single authority for uptime, coverage and SLA. Whole hours come from rollups and everything else from raw results,
 * and the two follow the same rules, so the source never changes the answer. A window the retained data cannot rebuild
 * is reported as unavailable as a whole: a missing part is never estimated or counted as unknown time. Reads take no locks.
 */
final readonly class MonitorAvailability
{
    /** Days raw results stay readable for every plan (ADR-008), even while their partition still exists physically. */
    public const int RAW_RETENTION_DAYS = 30;

    /**
     * @param RollupSource $source Raw ledger reads.
     * @param ExclusionPeriods $exclusions Merged pauses, suspensions and maintenance.
     * @param ResultPartitions $partitions Retained raw months.
     * @param TeamEntitlement $entitlement Effective plan of the team.
     * @param PlanLimits $limits Rollup history allowed by that plan.
     */
    public function __construct(
        private RollupSource $source,
        private ExclusionPeriods $exclusions,
        private ResultPartitions $partitions,
        private TeamEntitlement $entitlement,
        private PlanLimits $limits,
    ) {
    }

    /**
     * Time before the monitor existed and time after the current instant are not part of the window.
     *
     * @param Monitor $monitor Monitor of the active team, with its identity and creation instant loaded.
     * @param TimeInterval $window Requested half-open UTC window.
     * @return AvailabilityReport Availability of the window; unavailable beyond the plan's history or without retained data.
     */
    public function between(Monitor $monitor, TimeInterval $window): AvailabilityReport
    {
        $now = CarbonImmutable::now('UTC');
        $monitored = $this->monitoredPart($monitor, $window, $now);

        if ($monitored === null) {
            return $this->reportWithoutMonitoredTime($monitor);
        }

        if ($monitored->start->isBefore($now->subDays($this->historyDays($monitor)))) {
            return AvailabilityReport::unavailable();
        }

        $total = new AvailabilityDurations(0, 0, 0, 0);
        $remaining = [$monitored];
        $rollups = $this->rollupRange($monitor, $monitored);

        if ($rollups !== null) {
            $total = $rollups['durations'];
            $remaining = $this->outside($monitored, $rollups['start'], $rollups['end']);
        }

        foreach ($remaining as $part) {
            $durations = $this->rawDurations($monitor, $part, $now);

            if ($durations === null) {
                return AvailabilityReport::unavailable();
            }

            $total = $total->plus($durations);
        }

        return new AvailabilityReport($total);
    }

    /**
     * Reads raw results only, with a single query, which suits a short recent window split into a chart.
     *
     * @param Monitor $monitor Monitor of the active team, with its identity and creation instant loaded.
     * @param TimeInterval $window Requested half-open UTC window; its last hour may be shorter.
     * @return list<AvailabilityReport> One report per hour counted from the window start.
     */
    public function hourly(Monitor $monitor, TimeInterval $window): array
    {
        $now = CarbonImmutable::now('UTC');
        $hours = [];

        for ($start = $window->start; $start->isBefore($window->end); $start = $start->addHour()) {
            $hours[] = new TimeInterval($start, $start->addHour()->min($window->end));
        }

        $monitored = $this->monitoredPart($monitor, $window, $now);

        if ($monitored === null) {
            return array_map(fn (): AvailabilityReport => $this->reportWithoutMonitoredTime($monitor), $hours);
        }

        $timeline = $this->rawTimeline($monitor, $monitored, $now);

        if ($timeline === null) {
            return array_map(static fn (): AvailabilityReport => AvailabilityReport::unavailable(), $hours);
        }

        $reports = [];

        foreach ($hours as $hour) {
            $part = $hour->clip($monitored);

            $reports[] = new AvailabilityReport($part === null
                ? new AvailabilityDurations(0, 0, 0, 0)
                : $timeline['timeline']->durations($part->startMicroseconds(), $part->endMicroseconds(), $timeline['exclusions']));
        }

        return $reports;
    }

    /**
     * @param Monitor $monitor Monitor being read.
     * @param TimeInterval $window Requested window.
     * @param CarbonImmutable $now Current instant.
     * @return TimeInterval|null Part of the window inside the monitor's life so far; null when there is none or its start is unknown.
     */
    private function monitoredPart(Monitor $monitor, TimeInterval $window, CarbonImmutable $now): ?TimeInterval
    {
        $createdAt = $monitor->created_at;

        if ($createdAt === null || !$now->isAfter($createdAt)) {
            return null;
        }

        return $window->clip(new TimeInterval($createdAt, $now));
    }

    /**
     * @param Monitor $monitor Monitor being read.
     * @return AvailabilityReport Zero durations, which yield null ratios; unavailable when the monitor's start was not loaded.
     */
    private function reportWithoutMonitoredTime(Monitor $monitor): AvailabilityReport
    {
        if ($monitor->created_at === null) {
            return AvailabilityReport::unavailable();
        }

        return new AvailabilityReport(new AvailabilityDurations(0, 0, 0, 0));
    }

    /**
     * @param Monitor $monitor Monitor being read.
     * @return int Rollup history of the team's current plan, so a downgrade applies at once and physical cleanup may follow later.
     */
    private function historyDays(Monitor $monitor): int
    {
        $team = Team::query()->findOrFail($monitor->team_id);

        return $this->limits->rollupRetentionDays($this->entitlement->effectivePlan($team));
    }

    /**
     * @param Monitor $monitor Monitor being read.
     * @param TimeInterval $monitored Monitored part of the window.
     * @return array{start: CarbonImmutable, end: CarbonImmutable, durations: AvailabilityDurations}|null Durations of the unbroken run of stored hours that starts the whole-hour part of the window; null when that run is absent or has holes.
     */
    private function rollupRange(Monitor $monitor, TimeInterval $monitored): ?array
    {
        $start = $this->firstWholeHour($monitor, $monitored);
        $end = $monitored->end->startOfHour();

        if (!$end->isAfter($start)) {
            return null;
        }

        $row = CheckRollupHourly::query()
            ->where('team_id', $monitor->team_id)
            ->where('monitor_id', $monitor->id)
            ->where('hour', '>=', $start->format('Y-m-d\TH:i:s\Z'))
            ->where('hour', '<', $end->format('Y-m-d\TH:i:s\Z'))
            ->toBase()
            ->selectRaw('COUNT(*) AS hours, MIN(hour) AS first_hour, MAX(hour) AS last_hour')
            ->selectRaw('SUM(up_us) AS up_us, SUM(down_us) AS down_us, SUM(unknown_us) AS unknown_us, SUM(excluded_us) AS excluded_us')
            ->first();

        if ($row === null || (int) $row->hours === 0) {
            return null;
        }

        $first = CarbonImmutable::parse((string) $row->first_hour)->utc();
        $last = CarbonImmutable::parse((string) $row->last_hour)->utc();

        // Any hole sends the whole window to the raw results, which either rebuild it or declare it unavailable.
        if (!$first->equalTo($start) || (int) $first->diffInHours($last) + 1 !== (int) $row->hours) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $last->addHour(),
            'durations' => new AvailabilityDurations((int) $row->up_us, (int) $row->down_us, (int) $row->unknown_us, (int) $row->excluded_us),
        ];
    }

    /**
     * The first rollup of a monitor already starts at its creation, so it covers a window starting there exactly.
     *
     * @param Monitor $monitor Monitor being read.
     * @param TimeInterval $monitored Monitored part of the window.
     * @return CarbonImmutable First stored hour that lies entirely inside the monitored part.
     */
    private function firstWholeHour(Monitor $monitor, TimeInterval $monitored): CarbonImmutable
    {
        $hour = $monitored->start->startOfHour();

        if ($hour->equalTo($monitored->start) || $monitor->created_at?->equalTo($monitored->start) === true) {
            return $hour;
        }

        return $hour->addHour();
    }

    /**
     * @param TimeInterval $monitored Monitored part of the window.
     * @param CarbonImmutable $start Start of the part covered by rollups, possibly before the monitored start.
     * @param CarbonImmutable $end End of the part covered by rollups.
     * @return list<TimeInterval> Edges of the monitored part not covered by rollups.
     */
    private function outside(TimeInterval $monitored, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $parts = [];

        if ($start->isAfter($monitored->start)) {
            $parts[] = new TimeInterval($monitored->start, $start);
        }

        if ($monitored->end->isAfter($end)) {
            $parts[] = new TimeInterval($end, $monitored->end);
        }

        return $parts;
    }

    /**
     * @param Monitor $monitor Monitor being read.
     * @param TimeInterval $part Period to calculate from raw results.
     * @param CarbonImmutable $now Current instant.
     * @return AvailabilityDurations|null Durations of the period; null when raw results cannot rebuild it.
     */
    private function rawDurations(Monitor $monitor, TimeInterval $part, CarbonImmutable $now): ?AvailabilityDurations
    {
        $timeline = $this->rawTimeline($monitor, $part, $now);

        return $timeline === null
            ? null
            : $timeline['timeline']->durations($part->startMicroseconds(), $part->endMicroseconds(), $timeline['exclusions']);
    }

    /**
     * Slots whose regional work can still finish are left out: their verdict is not final, and the earlier one keeps holding until it expires.
     *
     * @param Monitor $monitor Monitor being read.
     * @param TimeInterval $part Period to calculate from raw results.
     * @param CarbonImmutable $now Current instant.
     * @return array{timeline: AvailabilityTimeline, exclusions: list<array{start: int, end: int}>}|null Calculation inputs; null when raw results for the period, or for verdicts reaching into it, are gone or no longer readable.
     */
    private function rawTimeline(Monitor $monitor, TimeInterval $part, CarbonImmutable $now): ?array
    {
        if (!$this->rawResultsRetained($monitor, $part, $now)) {
            return null;
        }

        $verdicts = array_map(
            static fn (SlotRecord $record) => $record->verdict(),
            $this->source->slots($monitor->team_id, $monitor->id, $part->start, $part->end, $now),
        );
        $exclusions = [];

        foreach ($this->exclusions->between($monitor, $part) as $interval) {
            $exclusions[] = ['start' => $interval->startMicroseconds(), 'end' => $interval->endMicroseconds()];
        }

        return ['timeline' => new AvailabilityTimeline(array_values(array_filter($verdicts))), 'exclusions' => $exclusions];
    }

    /**
     * @param Monitor $monitor Monitor being read.
     * @param TimeInterval $part Period to calculate from raw results.
     * @param CarbonImmutable $now Current instant.
     * @return bool Whether the period is inside the readable window and every result that can affect it still exists.
     */
    private function rawResultsRetained(Monitor $monitor, TimeInterval $part, CarbonImmutable $now): bool
    {
        if ($part->start->isBefore($now->subDays(self::RAW_RETENTION_DAYS))) {
            return false;
        }

        $oldest = $this->partitions->oldestMonth();

        if ($oldest === null) {
            return false;
        }

        // A verdict older than the monitor cannot exist, so only the monitor's own history must have survived.
        $needed = $part->start->subSeconds($this->source->lookbackSeconds());

        return !$needed->max($monitor->created_at ?? $needed)->isBefore($oldest);
    }
}
