<?php

declare(strict_types=1);

/** Carry a tenant-owned receipt snapshot selected at a scheduled slot, without credentials. */

namespace App\Data\Monitoring\Checks;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Future receipts must never be substituted for a missed heartbeat at an earlier slot. */
final readonly class HeartbeatSnapshot
{
    /**
     * @param int $teamId Owning tenant independently admitted by the runner.
     * @param int $monitorId Owning monitor.
     * @param CarbonImmutable $startedAt Current generation's UTC arming instant.
     * @param CarbonImmutable|null $receivedAt Latest receipt at or before the reference slot in that generation.
     * @throws InvalidArgumentException When identity or receipt ordering is invalid.
     */
    public function __construct(public int $teamId, public int $monitorId, public CarbonImmutable $startedAt, public ?CarbonImmutable $receivedAt)
    {
        if (min($teamId, $monitorId) < 1 || ($receivedAt !== null && $receivedAt->isBefore($startedAt))) {
            throw new InvalidArgumentException('Heartbeat snapshot fields are inconsistent.');
        }
    }
}
