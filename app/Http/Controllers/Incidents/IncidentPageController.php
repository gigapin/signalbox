<?php

declare(strict_types=1);

/** Render the team incident list and one incident's timeline from tenant-scoped reads. */

namespace App\Http\Controllers\Incidents;

use App\Actions\Incidents\ManageIncidents;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Incidents\IncidentPresenter;
use App\Http\Presenters\Notifications\NotificationPresenter;
use App\Http\Requests\Incidents\ListIncidentsRequest;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Services\Incidents\IncidentListing;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every page authorizes through IncidentPolicy before loading private data; another team's incident is
 * already a 404 from the scoped route binding. Permission props are hints; mutations re-check under lock.
 */
final class IncidentPageController extends Controller
{
    /** @var int Oldest delivery log rows shown on the incident page. */
    private const int DELIVERY_LIMIT = 100;

    /** @param IncidentPresenter $presenter Safe page projections. */
    public function __construct(private readonly IncidentPresenter $presenter)
    {
    }

    /**
     * @param ListIncidentsRequest $request Authorized read with tolerant filter parsing.
     * @param IncidentListing $listing Tenant-filtered incident pages.
     * @param int $team Route team, already established as tenant context.
     * @return Response Filtered page of incidents, accepted filters and rejected keys.
     */
    public function index(ListIncidentsRequest $request, IncidentListing $listing, int $team): Response
    {
        $filter = $request->filter();

        return Inertia::render('Incidents/Index', [
            'team' => $this->teamSummary($team),
            'incidents' => $this->presenter->page($listing->page($team, $filter)),
            'filters' => $filter->toArray(),
            'filtered' => $filter->isFiltered(),
            'rejectedFilters' => $request->rejectedFilters(),
            'options' => IncidentPresenter::options(),
        ]);
    }

    /**
     * @param NotificationPresenter $notifications Safe delivery rows.
     * @param int $team Route team.
     * @param Incident $incident Incident bound inside the route team.
     * @return Response Detail with the oldest-first timeline, its deliveries, the statuses a new entry may carry and permissions.
     */
    public function show(NotificationPresenter $notifications, int $team, Incident $incident): Response
    {
        Gate::authorize('view', $incident);
        $incident->load(['monitor:id,team_id,name', 'acknowledgedBy:id,name', 'updates.author:id,name']);

        return Inertia::render('Incidents/Show', [
            'team' => $this->teamSummary($team),
            'incident' => $this->presenter->detail($incident),
            'deliveries' => NotificationLog::query()
                ->where('incident_id', $incident->id)
                ->with('channel:id,team_id,name')
                ->orderBy('id')
                ->limit(self::DELIVERY_LIMIT)
                ->get()
                ->map(static fn (NotificationLog $log): array => $notifications->delivery($log))
                ->all(),
            'postableStatuses' => $this->presenter->postableStatuses($incident),
            'options' => IncidentPresenter::options(),
            'textMaxLength' => ManageIncidents::TEXT_MAX_LENGTH,
            'can' => ['update' => Gate::allows('update', $incident)],
        ]);
    }

    /**
     * @param int $team Route team whose membership the middleware has verified.
     * @return array{id: int, name: string} Minimal identity for headings and links.
     */
    private function teamSummary(int $team): array
    {
        $identity = Team::query()->findOrFail($team);

        return ['id' => $identity->id, 'name' => $identity->name];
    }
}
