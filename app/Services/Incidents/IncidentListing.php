<?php

declare(strict_types=1);

/** Page through a team's incidents with explicit tenant constraints and a stable order. */

namespace App\Services\Incidents;

use App\Data\Incidents\IncidentListFilter;
use App\Models\Incident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * TeamScope already limits the query to the active team; ADR-003 also requires an explicit team predicate on
 * list and aggregate queries, added here independently of the scope.
 */
final readonly class IncidentListing
{
    /** Rows per page; fixed so page links stay stable across requests. */
    public const int PER_PAGE = 20;

    /**
     * Unresolved incidents come first, then the most recent failures; the ID breaks ties so rows with equal
     * instants never swap between pages.
     *
     * @param int $teamId Active tenant; must equal the TenantContext team for TeamScope to agree.
     * @param IncidentListFilter $filter Validated filters and page.
     * @return LengthAwarePaginator<int, Incident> Page with each incident's monitor identity loaded.
     */
    public function page(int $teamId, IncidentListFilter $filter): LengthAwarePaginator
    {
        $query = Incident::query()
            ->where('team_id', $teamId)
            ->with('monitor:id,team_id,name');

        $this->constrain($query, $filter);

        return $query
            ->orderByRaw('resolved_at IS NULL DESC')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $filter->page);
    }

    /**
     * @param int $teamId Active tenant.
     * @param int $monitorId Monitor of that team.
     * @param int $limit Maximum number of incidents returned.
     * @return Collection<int, Incident> Latest incidents of the monitor, newest first.
     */
    public function recentForMonitor(int $teamId, int $monitorId, int $limit): Collection
    {
        return Incident::query()
            ->where('team_id', $teamId)
            ->where('monitor_id', $monitorId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param Builder<Incident> $query Query already holding the team predicate.
     * @param IncidentListFilter $filter Exact filters to add conjunctively.
     */
    private function constrain(Builder $query, IncidentListFilter $filter): void
    {
        $filter->state?->constrain($query);

        if ($filter->severity !== null) {
            $query->where('severity', $filter->severity->value);
        }
    }
}
