<?php

declare(strict_types=1);

/** Distinguish why a monitor did not execute checks during a recorded period. */

namespace App\Enums\Monitoring;

/** Both kinds are excluded from SLA time; they are recorded independently and may overlap. */
enum PauseKind: string
{
    /** A person paused the monitor. */
    case Voluntary = 'voluntary';

    /** Plan reconciliation suspended the monitor. */
    case PlanSuspension = 'plan_suspension';
}
