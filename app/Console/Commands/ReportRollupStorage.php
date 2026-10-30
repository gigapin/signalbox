<?php

declare(strict_types=1);

/** Print the measured storage cost of the check rollups for an operator. */

namespace App\Console\Commands;

use App\Services\Metrics\Rollups\RollupStorageReport;
use Illuminate\Console\Command;

/** Read-only and limited to aggregates; it scans both rollup tables, so it is run deliberately and never scheduled. */
final class ReportRollupStorage extends Command
{
    /** @var string Stable operational entry point. */
    protected $signature = 'metrics:rollup-storage';

    /** @var string Purpose displayed by artisan list. */
    protected $description = 'Report the measured storage cost of hourly and daily check rollups';

    /**
     * @param RollupStorageReport $report Aggregate measurement.
     * @return int Success after printing the measurement.
     */
    public function handle(RollupStorageReport $report): int
    {
        $rows = [];

        foreach ($report->measure() as $level) {
            $rows[] = [
                $level->level,
                $level->rows,
                round($level->averageDistinctValues, 1),
                $level->maximumDistinctValues,
                round($level->averageFrequencyBytes, 1),
                $level->maximumFrequencyBytes,
                round($level->averageRowBytes, 1),
                $level->tableBytes,
            ];
        }

        $this->table(
            ['Level', 'Rows', 'Avg distinct ms', 'Max distinct ms', 'Avg map bytes', 'Max map bytes', 'Avg row bytes', 'Table bytes'],
            $rows,
        );

        return self::SUCCESS;
    }
}
