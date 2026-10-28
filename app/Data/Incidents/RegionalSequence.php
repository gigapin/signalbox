<?php

declare(strict_types=1);

/** Track one region's consecutive observed failures for one monitor execution revision. */

namespace App\Data\Incidents;

use App\Data\Monitoring\RecordedResult;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;

/**
 * Sequences advance only in slot order. A success, a missing or infrastructure outcome, or a skipped slot
 * interrupts the sequence because none of them proves a consecutive target failure (ADR-007).
 */
final readonly class RegionalSequence
{
    /**
     * @param MonitorRegion $region Region this sequence belongs to.
     * @param int $revision Monitor execution revision the counters were built from.
     * @param CarbonImmutable|null $lastSlotAt Latest processed slot; null before the first result of the revision.
     * @param MonitorStatus $lastStatus Outcome of the latest processed slot; unknown for non-observations.
     * @param int $consecutiveFailures Nonnegative count of consecutive observed failures ending at the latest slot.
     * @param CarbonImmutable|null $startedAt First failing slot of the current sequence; null when the count is zero.
     * @param CheckError|null $lastError Cause of the latest failure; null when the count is zero.
     */
    public function __construct(
        public MonitorRegion $region,
        public int $revision,
        public ?CarbonImmutable $lastSlotAt,
        public MonitorStatus $lastStatus,
        public int $consecutiveFailures,
        public ?CarbonImmutable $startedAt,
        public ?CheckError $lastError,
    ) {
    }

    /**
     * @param MonitorRegion $region Region without processed results.
     * @param int $revision Current monitor execution revision.
     * @return self Empty sequence.
     */
    public static function fresh(MonitorRegion $region, int $revision): self
    {
        return new self($region, $revision, null, MonitorStatus::Unknown, 0, null, null);
    }

    /**
     * @param RecordedResult $result Result of this region; a different revision restarts from an empty sequence.
     * @param CarbonImmutable $previousSlot Scheduled slot immediately before the result's slot.
     * @return self|null Advanced sequence, or null when the slot is not after the latest processed slot.
     */
    public function advance(RecordedResult $result, CarbonImmutable $previousSlot): ?self
    {
        $base = $this;

        if ($result->monitorRevision !== $this->revision) {
            $base = self::fresh($this->region, $result->monitorRevision);
        }

        if ($base->lastSlotAt !== null && !$result->slotAt->isAfter($base->lastSlotAt)) {
            return null;
        }

        if (!$result->isObservedFailure()) {
            $status = $result->kind === CheckResultKind::Observed ? $result->status : MonitorStatus::Unknown;

            return new self($this->region, $base->revision, $result->slotAt, $status, 0, null, null);
        }

        $continues = $base->consecutiveFailures > 0
            && $base->lastSlotAt !== null
            && $base->lastSlotAt->equalTo($previousSlot);

        if (!$continues) {
            return new self($this->region, $base->revision, $result->slotAt, MonitorStatus::Down, 1, $result->slotAt, $result->error);
        }

        return new self(
            $this->region,
            $base->revision,
            $result->slotAt,
            MonitorStatus::Down,
            $base->consecutiveFailures + 1,
            $base->startedAt,
            $result->error,
        );
    }

    /**
     * @param int $revision Current monitor execution revision.
     * @param CarbonImmutable $slot Slot being evaluated.
     * @return bool Whether this sequence already processed exactly that slot of that revision.
     */
    public function covers(int $revision, CarbonImmutable $slot): bool
    {
        return $this->revision === $revision && $this->lastSlotAt?->equalTo($slot) === true;
    }
}
