<?php

declare(strict_types=1);

/** Read mandatory positive heartbeat traffic bounds without silently disabling throttling. */

namespace App\Services\Monitoring;

use LogicException;

/** Operational traffic limits are separate from plan eligibility and the monitor's rolling cadence. */
final class HeartbeatLimits
{
    /**
     * @param string $name Configuration key inside heartbeat.php, named with its time unit.
     * @return int Positive configured request limit per minute.
     * @throws LogicException When the operational bound is missing or invalid.
     */
    public function get(string $name): int
    {
        $value = config('heartbeat.'.$name);

        if (!is_int($value) || $value < 1) {
            throw new LogicException('Heartbeat traffic limits must be positive integers.');
        }

        return $value;
    }
}
