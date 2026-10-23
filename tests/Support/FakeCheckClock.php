<?php

declare(strict_types=1);

/** Advance a monotonic clock explicitly while simulating HTTP completion without waiting. */

namespace Tests\Support;

use App\Contracts\Monitoring\CheckClock;

/** Wall-clock time remains controlled independently through Carbon in tests. */
final class FakeCheckClock implements CheckClock
{
    /** @var float Current artificial monotonic reading in milliseconds. */
    public float $now = 0.0;

    /** @return float Artificial monotonic milliseconds. */
    public function milliseconds(): float
    {
        return $this->now;
    }
}
