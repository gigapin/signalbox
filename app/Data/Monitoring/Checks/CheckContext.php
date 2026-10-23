<?php

declare(strict_types=1);

/** Carry scalar execution identity without serializing private monitor configuration. */

namespace App\Data\Monitoring\Checks;

use App\Enums\Monitoring\MonitorRegion;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** An execution context identifies already admitted work; it does not grant permission to run it. */
final readonly class CheckContext
{
    /**
     * @param int $teamId Owning tenant, independently established by the caller.
     * @param int $monitorId Monitor identity.
     * @param int $executionId Regional execution identity used to correlate outcomes.
     * @param MonitorRegion $region Simulated execution region.
     * @param CarbonImmutable $referenceAt Scheduled reference instant, normalized to UTC by the caller.
     * @throws InvalidArgumentException When an identity is not positive.
     */
    public function __construct(
        public int $teamId,
        public int $monitorId,
        public int $executionId,
        public MonitorRegion $region,
        public CarbonImmutable $referenceAt,
    ) {
        if (min($teamId, $monitorId, $executionId) < 1) {
            throw new InvalidArgumentException('Check identities must be positive.');
        }
    }
}
