<?php

declare(strict_types=1);

/** Announce that an incident was resolved automatically or by a person, after the transaction commits. */

namespace App\Events\Incidents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Minimal identities only; consumers reload current state inside their own tenant context. */
final readonly class IncidentResolved implements ShouldDispatchAfterCommit
{
    /** Provide static dispatch helpers. */
    use Dispatchable;

    /**
     * @param int $teamId Owning tenant.
     * @param int $incidentId Affected incident.
     * @param int $monitorId Monitor of the incident.
     */
    public function __construct(public int $teamId, public int $incidentId, public int $monitorId)
    {
    }
}
