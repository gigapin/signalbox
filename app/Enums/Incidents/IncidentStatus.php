<?php

declare(strict_types=1);

/** Represent the incident lifecycle shared by the private timeline and future public status pages. */

namespace App\Enums\Incidents;

/**
 * Open states may move freely between each other; resolution closes the incident and only a reopening leaves it.
 * Acknowledgement is tracked separately and never changes the status.
 */
enum IncidentStatus: string
{
    /** Confirmed failure whose cause is still being investigated; the initial and reopened state. */
    case Investigating = 'investigating';

    /** The cause is known and a fix is in progress. */
    case Identified = 'identified';

    /** A fix is applied and the team is watching for recovery. */
    case Monitoring = 'monitoring';

    /** Terminal state until a confirmed failure inside the reopen window returns it to investigating. */
    case Resolved = 'resolved';

    /** @return bool Whether the incident still counts as the monitor's single open incident. */
    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }

    /**
     * Same-state assignments are not transitions and are therefore rejected.
     *
     * @param self $target Requested next status.
     * @return bool Whether the lifecycle admits the change from this status to the target.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        if ($this->isOpen()) {
            return true;
        }

        // A resolved incident can only be reopened, which always restarts the investigation.
        return $target === self::Investigating;
    }
}
