<?php

declare(strict_types=1);

/** Calculate stable UTC cadence without depending on database or worker timing. */

namespace App\Services\Monitoring\Scheduling;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Anchor slots to the Unix epoch with an unsigned CRC32 phase. */
final class MonitorSlots
{
    /**
     * @param int $monitorId Positive persisted identity.
     * @param int $interval Positive cadence in seconds.
     * @param CarbonImmutable $at Reference instant, including fractional seconds.
     * @return CarbonImmutable Most recent slot at or before the reference, normalized to UTC.
     * @throws InvalidArgumentException When identity or cadence is not positive.
     */
    public function latest(int $monitorId, int $interval, CarbonImmutable $at): CarbonImmutable
    {
        if ($monitorId < 1 || $interval < 1) {
            throw new InvalidArgumentException('Slot identity and interval must be positive.');
        }

        $phase = (int) sprintf('%u', crc32((string) $monitorId)) % $interval;
        $offset = (($at->getTimestamp() - $phase) % $interval + $interval) % $interval;

        return CarbonImmutable::createFromTimestampUTC($at->getTimestamp() - $offset);
    }

    /**
     * @param int $monitorId Positive persisted identity.
     * @param int $interval Positive cadence in seconds.
     * @param CarbonImmutable $at Reference instant.
     * @return CarbonImmutable First slot strictly after the reference, even at an exact boundary.
     */
    public function next(int $monitorId, int $interval, CarbonImmutable $at): CarbonImmutable
    {
        return $this->latest($monitorId, $interval, $at)->addSeconds($interval);
    }
}
