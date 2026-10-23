<?php

declare(strict_types=1);

/** Describe a team's monitor quota consumption for display, without granting or reserving capacity. */

namespace App\Data\Monitoring;

/** Informational snapshot: mutations still re-check limits under the Team lock (ADR-004). */
final readonly class MonitorUsage
{
    /**
     * @param int $used Monitors currently held by the team, including paused and plan-suspended ones.
     * @param int $limit Maximum monitors allowed by the effective plan.
     * @param int $minimumIntervalSeconds Fastest cadence allowed by the effective plan, in seconds.
     */
    public function __construct(
        public int $used,
        public int $limit,
        public int $minimumIntervalSeconds,
    ) {
    }

    /** @return bool Whether one more creation would currently exceed the plan quota. */
    public function isFull(): bool
    {
        return $this->used >= $this->limit;
    }

    /** @return array{used: int, limit: int, minimumIntervalSeconds: int, full: bool} Page projection. */
    public function toArray(): array
    {
        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'minimumIntervalSeconds' => $this->minimumIntervalSeconds,
            'full' => $this->isFull(),
        ];
    }
}
