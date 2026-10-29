<?php

declare(strict_types=1);

/** Provide monotonic time independent of wall-clock adjustments for network budget accounting. */

namespace App\Contracts\Monitoring;

/** Fakes advance deterministically without sleeping. */
interface CheckClock
{
    /** @return float Monotonic milliseconds; only differences between readings have meaning. */
    public function milliseconds(): float;
}
