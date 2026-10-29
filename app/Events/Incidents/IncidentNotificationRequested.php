<?php

declare(strict_types=1);

/** Request durable notification preparation within the incident transaction, before public after-commit events. */

namespace App\Events\Incidents;

/** Synchronous by design: failure rolls back the transition and its notification intents together. */
final readonly class IncidentNotificationRequested
{
    /**
     * @param int $teamId Owning tenant established by the incident lifecycle.
     * @param int $incidentId Locked incident whose transition has just been recorded.
     * @param int $monitorId Monitor whose lock precedes the incident lock.
     * @param int $cycle Reopen counter identifying this incident episode.
     * @param string $event Controlled lifecycle event: incident.opened, incident.reopened or incident.resolved.
     */
    public function __construct(
        public int $teamId,
        public int $incidentId,
        public int $monitorId,
        public int $cycle,
        public string $event,
    ) {
    }
}
