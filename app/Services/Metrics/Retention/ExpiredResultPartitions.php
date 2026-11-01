<?php

declare(strict_types=1);

/** Remove whole result months once they have expired and their rollups have been verified (ADR-008). */

namespace App\Services\Metrics\Retention;

use App\Data\Metrics\PartitionDropOutcome;
use App\Services\Metrics\MonitorAvailability;
use App\Services\Metrics\Rollups\RollupSource;
use App\Services\Monitoring\Results\ResultPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raw results are never deleted row by row: an expired month waits until all of it can go. Besides the 30 readable days,
 * a month stays for one maximum verdict lookback, because the oldest readable instant may still rely on earlier verdicts.
 * That also covers observations recorded after their slot's month ended, which take at most one maximum interval.
 */
final readonly class ExpiredResultPartitions
{
    /**
     * @param ResultPartitions $partitions Existing months and their removal.
     * @param RollupVerification $verification Proof required before removal.
     * @param RollupSource $source Longest possible verdict lookback.
     */
    public function __construct(
        private ResultPartitions $partitions,
        private RollupVerification $verification,
        private RollupSource $source,
    ) {
    }

    /** @return PartitionDropOutcome Months removed and months expired but kept, with the reason for keeping each. */
    public function drop(): PartitionDropOutcome
    {
        $lookback = $this->source->lookbackSeconds();
        $cutoff = CarbonImmutable::now('UTC')->subDays(MonitorAvailability::RAW_RETENTION_DAYS)->subSeconds($lookback);
        $dropped = [];
        $blocked = [];

        foreach ($this->partitions->months() as $month) {
            $end = $month->addMonth();

            if ($end->isAfter($cutoff)) {
                break;
            }

            // A kept month must not leave a newer one dropped: the oldest partition marks where raw history begins.
            if ($blocked !== []) {
                $blocked[$month->format('Y-m')] = ['older_month_kept' => 1];

                continue;
            }

            $problems = $this->problems($month, $end->addSeconds($lookback));

            if ($problems !== []) {
                $blocked[$month->format('Y-m')] = $problems;
                Log::error('Expired result partition kept: its rollups are not verified.', ['month' => $month->format('Y-m'), 'problems' => $problems]);

                continue;
            }

            try {
                $dropped[] = $this->partitions->drop($month);
            } catch (Throwable $exception) {
                $blocked[$month->format('Y-m')] = ['drop_failed' => 1];
                Log::error('Expired result partition could not be dropped; the next run retries.', ['month' => $month->format('Y-m'), 'exception' => $exception::class]);
            }
        }

        return new PartitionDropOutcome($dropped, $blocked);
    }

    /**
     * @param CarbonImmutable $month Expired month.
     * @param CarbonImmutable $closedThrough Required rollup progress.
     * @return array<string, int> Verification problems, also when the verification itself fails.
     */
    private function problems(CarbonImmutable $month, CarbonImmutable $closedThrough): array
    {
        try {
            return $this->verification->problems($month, $closedThrough);
        } catch (Throwable $exception) {
            Log::error('Rollup verification failed.', ['month' => $month->format('Y-m'), 'exception' => $exception::class]);

            return ['verification_failed' => 1];
        }
    }
}
