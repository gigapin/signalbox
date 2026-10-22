<?php

declare(strict_types=1);

/** Describe heartbeat lateness tolerance; cadence is the monitor's shared interval_seconds. */

namespace App\Data\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Enums\Monitoring\MonitorType;
use InvalidArgumentException;

/** Credentials, receipt state and deadline evaluation are introduced with the heartbeat engine. */
final readonly class HeartbeatMonitorConfiguration implements MonitorConfiguration
{
    /**
     * @param int $graceSeconds Explicit nonnegative tolerance in seconds after the expected cadence.
     * @throws InvalidArgumentException When tolerance is negative.
     */
    public function __construct(public int $graceSeconds)
    {
        if ($graceSeconds < 0) {
            throw new InvalidArgumentException('Heartbeat tolerance must not be negative.');
        }
    }

    /** @return MonitorType Heartbeat observation identity. */
    public function type(): MonitorType
    {
        return MonitorType::Heartbeat;
    }
}
