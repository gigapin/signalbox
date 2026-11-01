<?php

declare(strict_types=1);

/** Read the public daily availability history of a status page's components from the daily rollups. */

namespace App\Services\StatusPages;

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\AvailabilityReport;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whole UTC days, as rollups store them, excluding today because its rollup does not exist until the day closes. The
 * history stops at 90 days for every plan (ADR-008). Durations of the monitors of a component are added together, so the
 * percentages are time-weighted. A day without observed time has null values: it is never counted as available.
 */
final readonly class PublicStatusHistory
{
    /** @var int Days of public history for every plan (ADR-008). */
    public const int DAYS = 90;

    /**
     * @param StatusPage $page Page whose components are read.
     * @param CarbonImmutable $now Current instant.
     * @return array<int, array{uptimePercent: float|null, days: list<array{date: string, state: string, uptimePercent: float|null, coveragePercent: float|null}>}> Component identity to its period total and its days, oldest first.
     */
    public function read(StatusPage $page, CarbonImmutable $now): array
    {
        $today = $now->utc()->startOfDay();
        $from = $today->subDays(self::DAYS);

        // A join the tenant scope cannot see, so the page and the team are both explicit.
        $rows = DB::table('check_rollups_daily as rollup')
            ->join('status_page_component_monitors as member', static function ($join): void {
                $join->on('member.team_id', '=', 'rollup.team_id')->on('member.monitor_id', '=', 'rollup.monitor_id');
            })
            ->where('member.status_page_id', $page->id)
            ->where('member.team_id', $page->team_id)
            ->where('rollup.day', '>=', $from->toDateString())
            ->where('rollup.day', '<', $today->toDateString())
            ->groupBy('member.status_page_component_id', 'rollup.day')
            ->selectRaw("member.status_page_component_id AS component_id, to_char(rollup.day, 'YYYY-MM-DD') AS day")
            ->selectRaw('SUM(rollup.up_us) AS up_us, SUM(rollup.down_us) AS down_us, SUM(rollup.unknown_us) AS unknown_us, SUM(rollup.excluded_us) AS excluded_us')
            ->get();

        $durations = [];

        foreach ($rows as $row) {
            $durations[(int) $row->component_id][(string) $row->day] = new AvailabilityDurations(
                (int) $row->up_us,
                (int) $row->down_us,
                (int) $row->unknown_us,
                (int) $row->excluded_us,
            );
        }

        $history = [];

        foreach ($durations as $componentId => $byDay) {
            $history[$componentId] = $this->component($byDay, $from);
        }

        return $history;
    }

    /**
     * @return array{uptimePercent: float|null, days: list<array{date: string, state: string, uptimePercent: float|null, coveragePercent: float|null}>} History of a component without any rollup.
     */
    public function empty(CarbonImmutable $now): array
    {
        return $this->component([], $now->utc()->startOfDay()->subDays(self::DAYS));
    }

    /**
     * @param array<string, AvailabilityDurations> $byDay Durations per ISO day; absent days have no data.
     * @param CarbonImmutable $from First day of the history.
     * @return array{uptimePercent: float|null, days: list<array{date: string, state: string, uptimePercent: float|null, coveragePercent: float|null}>} Period total and every day of the history.
     */
    private function component(array $byDay, CarbonImmutable $from): array
    {
        $days = [];
        $total = new AvailabilityDurations(0, 0, 0, 0);

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $date = $from->addDays($offset)->toDateString();
            $durations = $byDay[$date] ?? new AvailabilityDurations(0, 0, 0, 0);
            $report = new AvailabilityReport($durations);
            $total = $total->plus($durations);

            $days[] = [
                'date' => $date,
                'state' => $this->dayState($durations),
                'uptimePercent' => $report->observedPercent(),
                'coveragePercent' => $report->coveragePercent(),
            ];
        }

        return ['uptimePercent' => (new AvailabilityReport($total))->observedPercent(), 'days' => $days];
    }

    /**
     * @param AvailabilityDurations $durations Time split of one day.
     * @return string `down` with any down time, `up` with only up time observed, `none` without observed time.
     */
    private function dayState(AvailabilityDurations $durations): string
    {
        if ($durations->down > 0) {
            return 'down';
        }

        return $durations->up > 0 ? 'up' : 'none';
    }
}
