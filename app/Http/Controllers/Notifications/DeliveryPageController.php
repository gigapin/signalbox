<?php

declare(strict_types=1);

/** Render a bounded, authorized deliveries list with deliberate safe projections. */

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Notifications\NotificationPresenter;
use App\Http\Requests\Operations\ListDeliveriesRequest;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Services\Operations\DeliveryListing;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/** Domain mutations remain on the existing monitor/channel/incident endpoints. */
final class DeliveryPageController extends Controller
{
    /**
     * @param ListDeliveriesRequest $request Current membership and tolerated controls.
     * @param DeliveryListing $listing Tenant-constrained paginated read.
     * @param NotificationPresenter $presenter Existing safe domain projection.
     * @param int $team Authorized route team, independent of saved preference.
     * @return Response Current page, normalized controls and rejected names without raw values.
     */
    public function index(ListDeliveriesRequest $request, DeliveryListing $listing, NotificationPresenter $presenter, int $team): Response
    {
        $identity = Team::query()->findOrFail($team);
        $filter = $request->filter();
        $now = CarbonImmutable::now('UTC');
        $page = $listing->page($team, $filter);

        return Inertia::render('Notifications/Deliveries', [
            'team' => ['id' => $identity->id, 'name' => $identity->name],
            'listing' => [
                'data' => $page->getCollection()->map(static fn (NotificationLog $item): array => [
                    ...$presenter->delivery($item),
                    'incidentId' => $item->incident_id,
                    'monitor' => [
                        'id' => $item->incident->monitor->id,
                        'name' => $item->incident->monitor->name,
                    ],
                ])->all(),
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'filters' => ['q' => $filter->q, 'state' => $filter->state],
            'rejectedFilters' => $filter->rejected,
            'states' => ListDeliveriesRequest::STATES,
            'generatedAt' => $now->toIso8601String(),
        ]);
    }
}
