<?php

declare(strict_types=1);

/** Identify simulated execution regions without implying geographic probes. */

namespace App\Enums\Monitoring;

/** Identify simulated execution regions without implying geographic probes. */
enum MonitorRegion: string
{
    /** Use the checks:eu execution queue. */
    case Eu = 'eu';

    /** Use the checks:us execution queue. */
    case Us = 'us';
}
