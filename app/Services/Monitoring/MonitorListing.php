<?php

declare(strict_types=1);

/** Search and filter a team's monitors through Scout's database engine with explicit tenant constraints. */

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorListFilter;
use App\Models\Monitor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The database engine builds its query from Monitor::newQuery(), so TeamScope still applies; ADR-003 also
 * requires an explicit team predicate on search and aggregate queries, added here independently of the scope.
 * Searchable columns are the plaintext ones declared by Monitor::toSearchableArray().
 */
final readonly class MonitorListing
{
    /** Rows per page; fixed so page links stay stable across requests. */
    public const int PER_PAGE = 20;

    /**
     * @param int $teamId Active tenant; must equal the TenantContext team for TeamScope to agree.
     * @param MonitorListFilter $filter Validated search text, exact filters and page.
     * @return LengthAwarePaginator<int, Monitor> Page ordered by name then ID, so equal names never swap pages.
     */
    public function page(int $teamId, MonitorListFilter $filter): LengthAwarePaginator
    {
        return Monitor::search(self::likeLiteral($filter->query))
            ->where('team_id', $teamId)
            ->query(function (Builder $query) use ($filter): void {
                $this->constrain($query, $filter);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, 'page', $filter->page);
    }

    /**
     * Escape LIKE metacharacters so user text matches literally; PostgreSQL's default LIKE escape is backslash.
     *
     * @param string $text Raw trimmed search text.
     * @return string Text whose `\`, `%` and `_` no longer act as pattern syntax.
     */
    public static function likeLiteral(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    /**
     * @param Builder<Monitor> $query Engine query already holding the text and team predicates.
     * @param MonitorListFilter $filter Exact filters to add conjunctively.
     */
    private function constrain(Builder $query, MonitorListFilter $filter): void
    {
        if ($filter->type !== null) {
            $query->where('type', $filter->type->value);
        }

        if ($filter->status !== null) {
            $query->where('last_status', $filter->status->value);
        }

        $filter->state?->constrain($query);
    }
}
