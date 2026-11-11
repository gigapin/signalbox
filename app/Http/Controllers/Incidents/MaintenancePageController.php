<?php

declare(strict_types=1);

/** Render a bounded, authorized maintenance list with deliberate safe projections. */

namespace App\Http\Controllers\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Incidents\MaintenanceWindowPresenter;
use App\Http\Requests\Operations\ListMaintenanceRequest;
use App\Models\MaintenanceWindow;
use App\Models\Team;
use App\Services\Operations\MaintenanceListing;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/** Domain mutations remain on the existing monitor/channel/incident endpoints. */
final class MaintenancePageController extends Controller
{
    /**
     * @param ListMaintenanceRequest $request Current membership and tolerated controls.
     * @param MaintenanceListing $listing Tenant-constrained paginated read.
     * @param MaintenanceWindowPresenter $presenter Existing safe domain projection.
     * @param int $team Authorized route team, independent of saved preference.
     * @return Response Current page, normalized controls and rejected names without raw values.
     */
    public function index(ListMaintenanceRequest $request, MaintenanceListing $listing, MaintenanceWindowPresenter $presenter, int $team): Response
    {
        $identity = Team::query()->findOrFail($team);
        $filter = $request->filter();
        $now = CarbonImmutable::now('UTC');
        $page = $listing->page($team, $filter, $now);

        return Inertia::render('Maintenance/Index', [
            'team' => ['id' => $identity->id, 'name' => $identity->name],
            'listing' => [
                'data' => $page->getCollection()->map(static fn (MaintenanceWindow $item): array => [
                    ...$presenter->window($item, $now),
                    'monitor' => [
                        'id' => $item->monitor->id,
                        'name' => $item->monitor->name,
                    ],
                ])->all(),
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'filters' => ['q' => $filter->q, 'state' => $filter->state],
            'rejectedFilters' => $filter->rejected,
            'states' => ListMaintenanceRequest::STATES,
            'generatedAt' => $now->toIso8601String(),
        ]);
    }
}
