<?php

declare(strict_types=1);

/** Orchestrate the read-only private team dashboard without duplicating monitoring or SLA rules. */

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowDashboardRequest;
use App\Models\Team;
use App\Services\Dashboard\DashboardOverview;
use Inertia\Inertia;
use Inertia\Response;

/** Membership, verification and tenant context are established before the request and read service execute. */
final class DashboardController extends Controller
{
    /**
     * @param ShowDashboardRequest $request Authorized tolerant page/monitor selection.
     * @param DashboardOverview $overview Tenant-constrained dashboard projection.
     * @param int $team Authorized route tenant, independent of saved browser preference.
     * @return Response Dashboard with safe identity and no serialized domain models.
     */
    public function __invoke(ShowDashboardRequest $request, DashboardOverview $overview, int $team): Response
    {
        $selection = $request->selection();
        $identity = Team::query()->findOrFail($team);

        return Inertia::render('Dashboard/Show', [
            'team' => ['id' => $identity->id, 'name' => $identity->name],
            'dashboard' => $overview->read($team, $selection['monitorId'], $selection['page']),
            'rejectedFilters' => $selection['rejected'],
        ]);
    }
}
