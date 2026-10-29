<?php

declare(strict_types=1);

/** Narrow an incident list to unresolved or resolved incidents. */

namespace App\Enums\Incidents;

use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;

/**
 * Derived from `resolved_at`, which is set exactly when the status is resolved, so the predicate matches the
 * partial unique index `incidents_single_open`. The absence of a value in a filter means every incident.
 */
enum IncidentListState: string
{
    /** Investigating, identified or monitoring: `resolved_at` is null. */
    case Open = 'open';

    /** Resolved and not reopened since: `resolved_at` is set. */
    case Resolved = 'resolved';

    /** @param Builder<Incident> $query Incident query receiving the conjunctive predicate. */
    public function constrain(Builder $query): void
    {
        if ($this === self::Open) {
            $query->whereNull('resolved_at');

            return;
        }

        $query->whereNotNull('resolved_at');
    }
}
