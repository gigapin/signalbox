<?php

declare(strict_types=1);

/** Read delivery outcomes without hydrating encrypted recipient payloads or attempt fences. */

namespace App\Services\Operations;

use App\Data\Operations\OperationalListFilter;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** Team predicates protect both the delivery and its incident; deleted channels retain their history. */
final readonly class DeliveryListing
{
    /**
     * @param int $teamId Authorized route team.
     * @param OperationalListFilter $filter Accepted status, monitor search and page.
     * @return LengthAwarePaginator<int, NotificationLog> Twenty newest deliveries with minimal incident/monitor/channel relations.
     */
    public function page(int $teamId, OperationalListFilter $filter): LengthAwarePaginator
    {
        $query = NotificationLog::query()->where('team_id', $teamId)
            ->with(['channel:id,team_id,name', 'incident:id,team_id,monitor_id', 'incident.monitor:id,team_id,name'])
            ->whereHas('incident', function (Builder $incident) use ($teamId, $filter): void {
                $incident->where('team_id', $teamId)
                    ->whereHas('monitor', function (Builder $monitor) use ($teamId, $filter): void {
                        $monitor->where('team_id', $teamId);
                        if ($filter->q !== '') {
                            $monitor->whereRaw('strpos(lower(name), lower(?)) > 0', [$filter->q]);
                        }
                    });
            });

        if ($filter->state !== null) {
            $query->where('status', $filter->state);
        }

        return $query->orderByRaw('created_at DESC NULLS LAST')->orderByDesc('id')->paginate(20, [
            'id', 'team_id', 'incident_id', 'channel_id', 'channel_type', 'kind', 'step_index',
            'status', 'attempts', 'response', 'sent_at', 'created_at',
        ], 'page', $filter->page);
    }
}
