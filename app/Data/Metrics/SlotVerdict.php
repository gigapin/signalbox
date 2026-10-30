<?php

declare(strict_types=1);

/** Describe what one scheduled slot established about a monitor, and from which instant. */

namespace App\Data\Metrics;

use App\Enums\Monitoring\MonitorStatus;

/** Instants are UTC microseconds since the epoch; an unknown verdict ends earlier knowledge without adding any. */
final readonly class SlotVerdict
{
    /**
     * @param int $slotAt Scheduled slot, used to keep a late older slot from replacing a newer one.
     * @param int $effectiveAt Instant the verdict became known: the latest regional observation.
     * @param int $validUntil Exclusive end of the verdict when no later one arrives first.
     * @param MonitorStatus $status Up, down, or unknown for discordant or incomplete regional coverage.
     */
    public function __construct(
        public int $slotAt,
        public int $effectiveAt,
        public int $validUntil,
        public MonitorStatus $status,
    ) {
    }
}
