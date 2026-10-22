<?php

declare(strict_types=1);

/** Represent the latest observation without manufacturing availability before a check. */

namespace App\Enums\Monitoring;

/** Represent the latest observation without manufacturing availability before a check. */
enum MonitorStatus: string
{
    /** No valid current availability observation. */
    case Unknown = 'unknown';

    /** A valid observation reports availability. */
    case Up = 'up';

    /** A valid observation reports a target failure. */
    case Down = 'down';
}
