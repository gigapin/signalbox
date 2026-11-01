<?php

declare(strict_types=1);

/** Name what a status page says about a monitor, a component or the whole page right now. */

namespace App\Enums\StatusPages;

/** Unknown is a state of its own: missing data is never presented as operational. */
enum PublicHealth: string
{
    /** Every watched part answered recently with no confirmed incident. */
    case Operational = 'operational';

    /** Part of it has no recent valid observation. */
    case Unknown = 'unknown';

    /** A maintenance window is running on part of it. */
    case Maintenance = 'maintenance';

    /** A confirmed incident affects part of it. */
    case PartialOutage = 'partial_outage';

    /** A confirmed incident affects all of it. */
    case Outage = 'outage';
}
