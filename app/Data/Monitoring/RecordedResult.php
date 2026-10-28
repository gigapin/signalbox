<?php

declare(strict_types=1);

/** Describe a terminal check result just written, with only the identities and outcome consumers need. */

namespace App\Data\Monitoring;

use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;

/** Carries no configuration, response content or private diagnostic text. */
final readonly class RecordedResult
{
    /**
     * @param int $teamId Owning tenant.
     * @param int $monitorId Checked monitor.
     * @param MonitorRegion $region Simulated region that produced the result.
     * @param CarbonImmutable $slotAt UTC scheduled slot of the execution.
     * @param int $monitorRevision Monitor execution revision observed by the worker.
     * @param CheckResultKind $kind Observed, missed, cancelled or infrastructure outcome.
     * @param MonitorStatus $status Up or down only for observed results; otherwise unknown.
     * @param CheckError|null $error Stable classification, present exactly for observed failures.
     * @param CarbonImmutable $recordedAt UTC instant of the terminal transaction.
     */
    public function __construct(
        public int $teamId,
        public int $monitorId,
        public MonitorRegion $region,
        public CarbonImmutable $slotAt,
        public int $monitorRevision,
        public CheckResultKind $kind,
        public MonitorStatus $status,
        public ?CheckError $error,
        public CarbonImmutable $recordedAt,
    ) {
    }

    /** @return bool Whether the target was genuinely observed failing in this slot. */
    public function isObservedFailure(): bool
    {
        return $this->kind === CheckResultKind::Observed && $this->status === MonitorStatus::Down;
    }
}
