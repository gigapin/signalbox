<?php

declare(strict_types=1);

/** Advance and rebuild the rollups of each monitor over closed UTC hours. */

namespace App\Services\Metrics\Rollups;

use App\Data\Metrics\HourlyRollup;
use App\Data\Metrics\TimeInterval;
use App\Data\Monitoring\SchedulingPass;
use App\Exceptions\RollupSourceUnavailable;
use App\Models\Monitor;
use App\Models\Scopes\TeamScope;
use App\Services\Metrics\ExclusionPeriods;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A closed hour can no longer change: its slots are terminal or can only become unobserved, and past exclusions are
 * immutable. That makes calculation safe without locks, so at most one UTC day is calculated first and only then written
 * in a short transaction. Foreign keys take a share lock on the monitor row while writing, and the check engine locks
 * that row for every terminal transition: holding it during calculation would delay checks.
 */
final readonly class BuildCheckRollups
{
    /** Tolerance in seconds for reservations and terminal transitions still committing around the current instant. */
    private const int COMMIT_MARGIN_SECONDS = 120;

    /** Discovery page size. */
    private const int CHUNK_SIZE = 200;

    /**
     * @param TenantContext $context Bounded tenant lifetime.
     * @param RollupSource $source Raw ledger reads.
     * @param HourlyRollupCalculator $calculator Pure hourly calculation.
     * @param RollupStore $store Rollup persistence.
     * @param ExclusionPeriods $exclusions Merged pauses, suspensions and maintenance.
     * @param ResultPartitions $partitions Retained raw months.
     */
    public function __construct(
        private TenantContext $context,
        private RollupSource $source,
        private HourlyRollupCalculator $calculator,
        private RollupStore $store,
        private ExclusionPeriods $exclusions,
        private ResultPartitions $partitions,
    ) {
    }

    /** @return SchedulingPass Hours written across all monitors and monitors whose progress failed and will resume next run. */
    public function runPass(): SchedulingPass
    {
        $hours = 0;
        $failed = 0;

        Monitor::withoutGlobalScope(TeamScope::class)
            ->select(['id', 'team_id'])
            ->chunkById(self::CHUNK_SIZE, function ($monitors) use (&$hours, &$failed): void {
                foreach ($monitors as $monitor) {
                    // One failing monitor must not starve every later identity, including other tenants.
                    try {
                        $hours += $this->advance($monitor->team_id, $monitor->id);
                    } catch (Throwable $exception) {
                        $failed++;

                        Log::error('Check rollup failed; continuing with the next monitor.', [
                            'team_id' => $monitor->team_id,
                            'monitor_id' => $monitor->id,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        return new SchedulingPass($hours, $failed);
    }

    /**
     * @param int $teamId Discovered owner.
     * @param int $monitorId Monitor to bring up to date.
     * @return int Hours written; zero when no further hour has closed or another run is already advancing.
     */
    public function advance(int $teamId, int $monitorId): int
    {
        $total = 0;

        do {
            $written = $this->context->run($teamId, fn (): int => $this->advanceOneDay($teamId, $monitorId));
            $total += $written;
        } while ($written > 0);

        return $total;
    }

    /**
     * Rewrites hours already rolled up from the raw results; later hours remain the responsibility of advance().
     *
     * @param int $teamId Discovered owner.
     * @param int $monitorId Monitor to rebuild.
     * @param CarbonImmutable $from Requested start, rounded down to its hour.
     * @param CarbonImmutable $to Requested exclusive end, rounded down to its hour.
     * @return int Hours rewritten.
     * @throws RollupSourceUnavailable When the raw results needed by the start of the range are no longer retained.
     */
    public function rebuild(int $teamId, int $monitorId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = $from->utc()->startOfHour();
        $oldest = $this->partitions->oldestMonth();

        if ($oldest === null || $start->subSeconds($this->source->lookbackSeconds())->isBefore($oldest)) {
            throw new RollupSourceUnavailable('The raw results needed by this rebuild are no longer retained.');
        }

        $total = 0;

        while (true) {
            $written = $this->context->run($teamId, fn (): int => $this->rebuildOneDay($teamId, $monitorId, $start, $to->utc()->startOfHour()));

            if ($written === 0) {
                return $total;
            }

            $total += $written;
            $start = $start->addHours($written);
        }
    }

    /**
     * @param int $teamId Owning tenant, active in the context.
     * @param int $monitorId Monitor to advance.
     * @return int Hours written by this step.
     */
    private function advanceOneDay(int $teamId, int $monitorId): int
    {
        $monitor = Monitor::query()->where('team_id', $teamId)->find($monitorId, ['id', 'team_id', 'interval_seconds', 'created_at']);

        if ($monitor === null) {
            return 0;
        }

        $through = $this->cursor($teamId, $monitorId);
        $start = $through ?? $this->initialHour($monitor);

        if ($start === null) {
            return 0;
        }

        $end = $start->addDay()->startOfDay()->min($this->closedThrough($monitor));

        if (!$end->isAfter($start)) {
            return 0;
        }

        $rollups = $this->calculate($monitor, $start, $end);

        return DB::transaction(function () use ($teamId, $monitorId, $through, $start, $end, $rollups): int {
            if (!$this->monitorStillExists($teamId, $monitorId)) {
                return 0;
            }

            // Another run advanced since the unlocked read: it wrote these same hours and keeps going.
            if ($this->lockedCursor($teamId, $monitorId)?->getTimestamp() !== $through?->getTimestamp()) {
                return 0;
            }

            $this->write($teamId, $monitorId, $rollups, $start, $end);

            DB::table('check_rollup_cursors')
                ->where('team_id', $teamId)
                ->where('monitor_id', $monitorId)
                ->update(['hourly_through' => $end->format('Y-m-d\TH:i:s\Z')]);

            return (int) $start->diffInHours($end);
        });
    }

    /**
     * @param int $teamId Owning tenant, active in the context.
     * @param int $monitorId Monitor to rebuild.
     * @param CarbonImmutable $start Inclusive hour-aligned start of this step.
     * @param CarbonImmutable $to Exclusive hour-aligned end of the whole rebuild.
     * @return int Hours rewritten by this step, counted from its start.
     */
    private function rebuildOneDay(int $teamId, int $monitorId, CarbonImmutable $start, CarbonImmutable $to): int
    {
        $monitor = Monitor::query()->where('team_id', $teamId)->find($monitorId, ['id', 'team_id', 'created_at']);
        $through = $this->cursor($teamId, $monitorId);

        if ($monitor === null || $through === null) {
            return 0;
        }

        $end = $start->addDay()->startOfDay()->min($to)->min($through);

        if (!$end->isAfter($start)) {
            return 0;
        }

        $rollups = $this->calculate($monitor, $start, $end);

        return DB::transaction(function () use ($teamId, $monitorId, $rollups, $start, $end, $through): int {
            if (!$this->monitorStillExists($teamId, $monitorId)) {
                return 0;
            }

            $this->lockedCursor($teamId, $monitorId);
            $this->write($teamId, $monitorId, $rollups, $start, $through);

            return (int) $start->diffInHours($end);
        });
    }

    /**
     * Results older than the oldest partition are gone, so a monitor predating it starts where data still exists.
     *
     * @param Monitor $monitor Monitor without any rollup progress.
     * @return CarbonImmutable|null First hour to roll up; null when no result partition exists yet.
     */
    private function initialHour(Monitor $monitor): ?CarbonImmutable
    {
        $oldest = $this->partitions->oldestMonth();

        if ($oldest === null || $monitor->created_at === null) {
            return null;
        }

        return $monitor->created_at->utc()->startOfHour()->max($oldest);
    }

    /**
     * Future reservations pick the latest slot at their own instant, so they stay later than one current interval ago;
     * a changed interval restarts cadence after the change, which only moves them further ahead.
     *
     * @param Monitor $monitor Monitor being advanced.
     * @return CarbonImmutable Exclusive end of the hours that can no longer receive slots or observations.
     */
    private function closedThrough(Monitor $monitor): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $limit = $now->subSeconds($monitor->interval_seconds + self::COMMIT_MARGIN_SECONDS);
        $open = $this->source->oldestOpenSlot($monitor->team_id, $monitor->id, $now, self::COMMIT_MARGIN_SECONDS);

        return ($open === null ? $limit : $limit->min($open))->startOfHour();
    }

    /**
     * @param Monitor $monitor Monitor being rolled up, active in the tenant context.
     * @param CarbonImmutable $start Inclusive hour-aligned start.
     * @param CarbonImmutable $end Exclusive hour-aligned end.
     * @return list<HourlyRollup> Calculated hours; no lock is held while reading their inputs.
     */
    private function calculate(Monitor $monitor, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $window = new TimeInterval($start, $end);
        $exclusions = [];

        foreach ($this->exclusions->between($monitor, $window) as $interval) {
            $exclusions[] = ['start' => $interval->startMicroseconds(), 'end' => $interval->endMicroseconds()];
        }

        return $this->calculator->calculate(
            $this->source->slots($monitor->team_id, $monitor->id, $start, $end),
            $window->startMicroseconds(),
            $window->endMicroseconds(),
            (int) ($monitor->created_at ?? $start)->utc()->format('Uu'),
            $exclusions,
        );
    }

    /**
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor being written.
     * @param list<HourlyRollup> $rollups Calculated hours.
     * @param CarbonImmutable $start Start of the written hours, selecting their UTC day.
     * @param CarbonImmutable $closedThrough End of the hours rolled up after this write.
     */
    private function write(int $teamId, int $monitorId, array $rollups, CarbonImmutable $start, CarbonImmutable $closedThrough): void
    {
        $this->store->writeHours($teamId, $monitorId, $rollups);

        $day = $start->startOfDay();

        // Only a whole closed day has final hours; a monitor's partial first day is marked by the stored hours count.
        if (!$day->addDay()->isAfter($closedThrough)) {
            $this->store->rebuildDay($teamId, $monitorId, $day);
        }
    }

    /**
     * Taking the monitor before the cursor gives the same order as a monitor deletion cascading to its cursor, so the two cannot deadlock.
     *
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor about to be written.
     * @return bool Whether the monitor still exists, now protected from deletion until commit.
     */
    private function monitorStillExists(int $teamId, int $monitorId): bool
    {
        return DB::table('monitors')
            ->where('team_id', $teamId)
            ->where('id', $monitorId)
            ->lock('for key share')
            ->exists();
    }

    /**
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor being rolled up.
     * @return CarbonImmutable|null Progress without any lock: hours before this instant are rolled up; null before the first run.
     */
    private function cursor(int $teamId, int $monitorId): ?CarbonImmutable
    {
        $through = DB::table('check_rollup_cursors')
            ->where('team_id', $teamId)
            ->where('monitor_id', $monitorId)
            ->value('hourly_through');

        return is_string($through) ? CarbonImmutable::parse($through)->utc() : null;
    }

    /**
     * @param int $teamId Owning tenant.
     * @param int $monitorId Monitor whose rollups are being written.
     * @return CarbonImmutable|null Same progress, locked until commit so concurrent writers of one monitor serialize.
     */
    private function lockedCursor(int $teamId, int $monitorId): ?CarbonImmutable
    {
        DB::table('check_rollup_cursors')->insertOrIgnore(['team_id' => $teamId, 'monitor_id' => $monitorId]);

        $through = DB::table('check_rollup_cursors')
            ->where('team_id', $teamId)
            ->where('monitor_id', $monitorId)
            ->lockForUpdate()
            ->value('hourly_through');

        return is_string($through) ? CarbonImmutable::parse($through)->utc() : null;
    }
}
