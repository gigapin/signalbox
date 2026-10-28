<?php

declare(strict_types=1);

/** Classify what one complete regional slot proves about a monitor's availability. */

namespace App\Enums\Incidents;

/** Only confirmed and recovered verdicts may change incidents. */
enum SlotVerdictKind: string
{
    /** Every configured region failed in this slot and reached the confirmation threshold. */
    case Confirmed = 'confirmed';

    /** Every configured region observed the target up in this slot. */
    case Recovered = 'recovered';

    /** Disagreement, missing observations, an incomplete slot or a threshold not yet reached. */
    case Inconclusive = 'inconclusive';
}
