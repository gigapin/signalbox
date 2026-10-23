<?php

declare(strict_types=1);

/** Measure elapsed time using the operating system monotonic clock. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\CheckClock;

/** Network durations cannot become negative after a system wall-clock correction. */
final class MonotonicCheckClock implements CheckClock
{
    /** @return float Monotonic nanoseconds converted to milliseconds. */
    public function milliseconds(): float
    {
        return hrtime(true) / 1000000;
    }
}
