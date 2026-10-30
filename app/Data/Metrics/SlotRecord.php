<?php

declare(strict_types=1);

/** Gather the regional executions of one scheduled slot, as they were reserved for that slot. */

namespace App\Data\Metrics;

use App\Enums\Monitoring\MonitorStatus;

/** The reserved execution count, not the current monitor configuration, decides how many regions must agree. */
final readonly class SlotRecord
{
    /**
     * @param int $slotAt Scheduled slot in UTC microseconds since the epoch.
     * @param int $nextSlotAt Following expected slot, which is also this slot's acquisition expiry.
     * @param int $executions Regional executions reserved for the slot.
     * @param list<array{status: MonitorStatus, checkedAt: int, latencyMs: int|null}> $observations Genuine regional up/down observations.
     */
    public function __construct(
        public int $slotAt,
        public int $nextSlotAt,
        public int $executions,
        public array $observations,
    ) {
    }

    /**
     * ADR-009 validity: a sample holds until the following expected slot has also expired.
     *
     * @return SlotVerdict|null Slot verdict, or null when no region observed anything and earlier knowledge simply ages out.
     */
    public function verdict(): ?SlotVerdict
    {
        if ($this->observations === []) {
            return null;
        }

        $statuses = array_unique(array_map(static fn (array $observation): string => $observation['status']->value, $this->observations));
        $agreed = count($this->observations) === $this->executions && count($statuses) === 1;

        return new SlotVerdict(
            $this->slotAt,
            max(array_map(static fn (array $observation): int => $observation['checkedAt'], $this->observations)),
            $this->nextSlotAt + ($this->nextSlotAt - $this->slotAt),
            $agreed ? $this->observations[0]['status'] : MonitorStatus::Unknown,
        );
    }

    /** @return int Regional observations that found the target down. */
    public function failures(): int
    {
        return count(array_filter($this->observations, static fn (array $observation): bool => $observation['status'] === MonitorStatus::Down));
    }

    /** @return int Regional executions without an observation, including work still awaiting its terminal recovery. */
    public function unknownChecks(): int
    {
        return $this->executions - count($this->observations);
    }

    /** @return list<int> Measured latencies; zero is a real value and null measurements are left out. */
    public function latencies(): array
    {
        $latencies = [];

        foreach ($this->observations as $observation) {
            if ($observation['latencyMs'] !== null) {
                $latencies[] = $observation['latencyMs'];
            }
        }

        return $latencies;
    }
}
