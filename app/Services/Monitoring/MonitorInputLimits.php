<?php

declare(strict_types=1);

/** Read operational input bounds separately from effective subscription limits. */

namespace App\Services\Monitoring;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/** Reject missing or invalid configuration rather than treating a limit as unbounded. */
final readonly class MonitorInputLimits
{
    /** @param Repository $config Application configuration repository. */
    public function __construct(private Repository $config)
    {
    }

    /**
     * @param string $name Named positive bound in monitor-input configuration.
     * @return int Configured bound in the units indicated by its name.
     * @throws LogicException When configuration is absent or not a positive integer.
     */
    public function get(string $name): int
    {
        $value = $this->config->get('monitor-input.'.$name);

        if (!is_int($value) || $value < 1) {
            throw new LogicException('Monitor input limits require positive integer configuration.');
        }

        return $value;
    }
}
