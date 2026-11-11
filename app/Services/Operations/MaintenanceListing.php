<?php

declare(strict_types=1);

/** Read a bounded team maintenance list without duplicating scheduling or mutation rules. */

namespace App\Services\Operations;

use App\Data\Operations\OperationalListFilter;
use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** All predicates are conjunctive with TeamScope and the explicit route team. */
final readonly class MaintenanceListing
{
    /**
     * @param int $teamId Authorized route team.
     * @param OperationalListFilter $filter Accepted phase, monitor search and page.
     * @param CarbonImmutable $now Single half-open boundary shared with the presenter.
     * @return LengthAwarePaginator<int, MaintenanceWindow> Twenty windows, most recent start first, with monitor identity only.
     */
    public function page(int $teamId, OperationalListFilter $filter, CarbonImmutable $now): LengthAwarePaginator
    {
        $query = MaintenanceWindow::query()->where('team_id', $teamId)
            ->with('monitor:id,team_id,name')
            ->whereHas('monitor', function (Builder $monitor) use ($teamId, $filter): void {
                $monitor->where('team_id', $teamId);
                if ($filter->q !== '') {
                    // strpos treats percent and underscore as literal search characters.
                    $monitor->whereRaw('strpos(lower(name), lower(?)) > 0', [$filter->q]);
                }
            });

        match ($filter->state) {
            'upcoming' => $query->where('starts_at', '>', $now),
            'ongoing' => $query->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            'finished' => $query->where('ends_at', '<=', $now),
            default => null,
        };

        return $query->orderByDesc('starts_at')->orderByDesc('id')->paginate(20, ['*'], 'page', $filter->page);
    }
}
