<?php

declare(strict_types=1);

/** Summarize one scheduling pass so a single failing item is visible without stopping the others. */

namespace App\Data\Monitoring;

/** Failed items keep their previous state and are retried by the next pass. */
final readonly class SchedulingPass
{
    /**
     * @param int $processed Items whose operation produced its domain effect (reservations, publications, recoveries).
     * @param int $failed Items whose operation threw; their transactions were rolled back and logged by identity only.
     */
    public function __construct(
        public int $processed,
        public int $failed,
    ) {
    }
}
