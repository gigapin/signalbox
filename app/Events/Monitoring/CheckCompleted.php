<?php

declare(strict_types=1);

/** Announce one durably recorded terminal result without serializing a monitor or its network configuration. */

namespace App\Events\Monitoring;

use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Non-observation outcomes retain unknown status and nullable latency/time; this is not a monitor aggregate. */
final readonly class CheckCompleted implements ShouldDispatchAfterCommit
{
    /**
     * @param int $teamId Immutable owning tenant.
     * @param int $monitorId Monitor of that tenant.
     * @param int $executionId Unique terminal execution; repeated delivery must not append another result/event.
     * @param MonitorRegion $region Simulated regional worker.
     * @param CarbonImmutable $slotAt Scheduled slot, used with revision/region for client ordering.
     * @param int $monitorRevision Monitor definition used by this execution; historical revisions remain historical.
     * @param CheckResultKind $kind Observation, missed, cancelled or infrastructure result.
     * @param MonitorStatus $status Regional observed up/down, otherwise unknown.
     * @param int|null $responseTimeMs Persisted nearest-integer milliseconds, null when not measured.
     * @param CarbonImmutable|null $checkedAt Actual observation instant, null for non-observations.
     * @param CarbonImmutable $recordedAt Result recording instant, not a fabricated observation time.
     */
    public function __construct(
        public int $teamId,
        public int $monitorId,
        public int $executionId,
        public MonitorRegion $region,
        public CarbonImmutable $slotAt,
        public int $monitorRevision,
        public CheckResultKind $kind,
        public MonitorStatus $status,
        public ?int $responseTimeMs,
        public ?CarbonImmutable $checkedAt,
        public CarbonImmutable $recordedAt,
    ) {
    }
}
