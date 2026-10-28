<?php

declare(strict_types=1);

/** Hold the joint evaluation of one slot across the monitor's configured regions. */

namespace App\Data\Incidents;

use App\Enums\Incidents\SlotVerdictKind;
use App\Enums\Monitoring\CheckError;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** A confirmed verdict always carries the sequence start and the selected cause; other verdicts carry neither. */
final readonly class SlotVerdict
{
    /**
     * @param SlotVerdictKind $kind Joint outcome of the slot.
     * @param CarbonImmutable|null $startedAt Earliest first failing slot among the confirming regions.
     * @param CheckError|null $cause Cause of the most severe confirming region.
     * @throws InvalidArgumentException When the confirmation details do not match the kind.
     */
    public function __construct(
        public SlotVerdictKind $kind,
        public ?CarbonImmutable $startedAt = null,
        public ?CheckError $cause = null,
    ) {
        $confirmed = $kind === SlotVerdictKind::Confirmed;

        if ($confirmed !== ($startedAt !== null) || $confirmed !== ($cause !== null)) {
            throw new InvalidArgumentException('Only a confirmed verdict carries a start and a cause.');
        }
    }

    /** @return self Verdict that must not change incidents. */
    public static function inconclusive(): self
    {
        return new self(SlotVerdictKind::Inconclusive);
    }
}
