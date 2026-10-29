<?php

declare(strict_types=1);

/** Define the shared identity of the five real monitor configuration variants. */

namespace App\Contracts\Monitoring;

use App\Enums\Monitoring\MonitorType;

/** Keep configuration data independent of HTTP requests, persistence and network transports. */
interface MonitorConfiguration
{
    /** @return MonitorType Strategy identity represented by this immutable configuration. */
    public function type(): MonitorType;
}
