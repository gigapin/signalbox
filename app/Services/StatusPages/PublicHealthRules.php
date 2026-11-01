<?php

declare(strict_types=1);

/** Decide the public state of monitors and of the groups they form. */

namespace App\Services\StatusPages;

use App\Enums\Monitoring\MonitorOperationalState;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\StatusPages\PublicHealth;
use App\Models\Monitor;
use Carbon\CarbonImmutable;

/**
 * Only a confirmed incident makes something public as an outage: a first failed check below the confirmation threshold
 * can be a false alarm, and the threshold exists for that. Pure rules, with no query.
 */
final readonly class PublicHealthRules
{
    /** @var int Intervals after which an observation stops counting: one missed check is tolerated, as in the availability lookback. */
    private const int STALE_AFTER_INTERVALS = 2;

    /**
     * @param Monitor $monitor Monitor with flags, status, last check, interval and heartbeat grace loaded.
     * @param bool $hasOpenIncident Whether an unresolved incident exists for it.
     * @param bool $inMaintenance Whether a maintenance window is running now.
     * @param CarbonImmutable $now Current instant.
     * @return PublicHealth|null State of the monitor; null when paused or suspended, as it is not being watched.
     */
    public function monitor(Monitor $monitor, bool $hasOpenIncident, bool $inMaintenance, CarbonImmutable $now): ?PublicHealth
    {
        if (MonitorOperationalState::of($monitor) !== MonitorOperationalState::Active) {
            return null;
        }

        if ($hasOpenIncident) {
            return PublicHealth::Outage;
        }

        if ($inMaintenance) {
            return PublicHealth::Maintenance;
        }

        $checkedAt = $monitor->last_checked_at;

        // An observation without an expiry would keep claiming a state nobody has verified (ADR-009).
        if (
            $monitor->last_status === MonitorStatus::Unknown
            || $checkedAt === null
            || $checkedAt->addSeconds(self::STALE_AFTER_INTERVALS * $monitor->interval_seconds + (int) $monitor->heartbeat_grace_seconds)->isBefore($now)
        ) {
            return PublicHealth::Unknown;
        }

        return PublicHealth::Operational;
    }

    /**
     * @param list<PublicHealth|null> $parts States of the monitors of a component, or of the components of a page.
     * @return PublicHealth State of the group; unknown when nothing in it is being watched.
     */
    public function group(array $parts): PublicHealth
    {
        $watched = array_values(array_filter($parts));

        if ($watched === []) {
            return PublicHealth::Unknown;
        }

        $outages = array_filter($watched, static fn (PublicHealth $part): bool => $part === PublicHealth::Outage);

        if (count($outages) === count($watched)) {
            return PublicHealth::Outage;
        }

        if ($outages !== [] || in_array(PublicHealth::PartialOutage, $watched, true)) {
            return PublicHealth::PartialOutage;
        }

        if (in_array(PublicHealth::Maintenance, $watched, true)) {
            return PublicHealth::Maintenance;
        }

        if (in_array(PublicHealth::Unknown, $watched, true)) {
            return PublicHealth::Unknown;
        }

        return PublicHealth::Operational;
    }
}
