<?php

declare(strict_types=1);

/** Separate genuine target observations from absent, cancelled and infrastructure outcomes. */

namespace App\Enums\Monitoring;

/** Only observed results may contribute an up/down measurement to future incident and SLA evaluation. */
enum CheckResultKind: string
{
    /** A checker observed the target at an actual completion instant. */
    case Observed = 'observed';
    /** The slot expired before any worker acquired it. */
    case Missed = 'missed';
    /** The admitted worker could not durably complete an observation. */
    case Infrastructure = 'infrastructure_failed';
    /** Current configuration or eligibility invalidated unstarted work. */
    case Cancelled = 'cancelled';
}
