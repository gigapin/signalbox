<?php

declare(strict_types=1);

/** Read every team for platform operators, including the only cross-tenant monitor aggregate. */

namespace App\Services\Admin;

use App\Models\PlatformAuditEvent;
use App\Models\Team;
use App\Services\Monitoring\MonitorListing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Callers must already have passed the platform operator gate. Teams and users carry no tenant scope; the
 * monitor count is read with the query builder, deliberately outside TeamScope, and returns numbers only:
 * no monitor name, target, header, body or other configuration is ever selected here.
 */
final readonly class PlatformTeamDirectory
{
    /** @var int PER_PAGE Teams per page. */
    public const int PER_PAGE = 25;

    /** @var int EVENT_LIMIT Most recent audit events shown on a team page. */
    public const int EVENT_LIMIT = 50;

    /**
     * @param string $search Trimmed text matched literally against team name, slug and owner email.
     * @param int $page One-based page number.
     * @return LengthAwarePaginator<int, Team> Teams ordered by name then ID, with owner and override loaded.
     */
    public function page(string $search, int $page): LengthAwarePaginator
    {
        $query = Team::query()->with(['owner:id,name,email', 'planOverride']);

        if ($search !== '') {
            $pattern = '%'.MonitorListing::likeLiteral($search).'%';

            $query->where(function ($where) use ($pattern): void {
                $where->where('name', 'ilike', $pattern)
                    ->orWhere('slug', 'ilike', $pattern)
                    ->orWhereHas('owner', fn ($owner) => $owner->where('email', 'ilike', $pattern));
            });
        }

        return $query->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * @param list<int> $teamIds Teams whose monitors are counted.
     * @return array<int, int> Monitor count per team ID, paused and suspended monitors included; absent teams have none.
     */
    public function monitorCounts(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        return DB::table('monitors')
            ->whereIn('team_id', $teamIds)
            ->groupBy('team_id')
            ->selectRaw('team_id, count(*) as total')
            ->pluck('total', 'team_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * @param Team $team Team whose members are counted.
     * @return int Current memberships.
     */
    public function memberCount(Team $team): int
    {
        return DB::table('team_user')->where('team_id', $team->id)->count();
    }

    /**
     * @param Team $team Subject team.
     * @return Collection<int, PlatformAuditEvent> Latest events first, with the acting operator loaded.
     */
    public function events(Team $team): Collection
    {
        return PlatformAuditEvent::query()
            ->with('actor:id,email')
            ->where('subject_type', 'team')
            ->where('subject_id', $team->id)
            ->orderByDesc('id')
            ->limit(self::EVENT_LIMIT)
            ->get();
    }
}
