<?php

declare(strict_types=1);

/** Orchestrate the read-only private team dashboard without duplicating monitoring or SLA rules. */

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowDashboardRequest;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Dashboard\DashboardOverview;
use App\Services\Monitoring\MonitorLimits;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Membership, verification and tenant context are established before the request and read service execute. */
final class DashboardController extends Controller
{
    /**
     * @param ShowDashboardRequest $request Authorized tolerant page/monitor selection.
     * @param DashboardOverview $overview Tenant-constrained dashboard projection.
     * @param MonitorLimits $limits Read-only usage snapshot from the centralized plan policy.
     * @param int $team Authorized route tenant, independent of saved browser preference.
     * @return Response Dashboard with safe identity and no serialized domain models.
     */
    public function __invoke(ShowDashboardRequest $request, DashboardOverview $overview, MonitorLimits $limits, int $team): Response
    {
        $selection = $request->selection();
        $identity = Team::query()->findOrFail($team);

        return Inertia::render('Dashboard/Show', [
            'team' => ['id' => $identity->id, 'name' => $identity->name],
            'reportTimezone' => $identity->timezone,
            'dashboard' => [
                ...$overview->read($team, $selection['monitorId'], $selection['page']),
                'can' => ['create' => Gate::allows('create', Monitor::class)],
                'usage' => $limits->usage($identity)->toArray(),
            ],
            'rejectedFilters' => $selection['rejected'],
        ]);
    }
}
