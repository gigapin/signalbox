<?php

declare(strict_types=1);

/** Render monitor list, detail and form pages from tenant-scoped reads; mutations stay in MonitorController. */

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Monitoring\MonitorPresenter;
use App\Http\Requests\Monitoring\ListMonitorsRequest;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\MonitorLimits;
use App\Services\Monitoring\MonitorListing;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every page authorizes through MonitorPolicy before loading private data. Permission and quota props are
 * hints for the interface only; ManageMonitors re-checks both under lock on every mutation.
 */
final class MonitorPageController extends Controller
{
    /**
     * @param MonitorPresenter $presenter Safe page projections.
     * @param MonitorLimits $limits Plan quota and cadence minimums.
     */
    public function __construct(
        private readonly MonitorPresenter $presenter,
        private readonly MonitorLimits $limits,
    ) {
    }

    /**
     * @param ListMonitorsRequest $request Authorized read with tolerant filter parsing.
     * @param MonitorListing $listing Tenant-filtered Scout search.
     * @param int $team Route team, already established as tenant context.
     * @return Response Filtered page of monitors, accepted filters, rejected keys, quota and create permission.
     */
    public function index(ListMonitorsRequest $request, MonitorListing $listing, int $team): Response
    {
        $filter = $request->filter();
        $identity = $this->team($team);

        return Inertia::render('Monitors/Index', [
            'team' => $this->teamSummary($identity),
            'monitors' => $this->presenter->page($listing->page($team, $filter)),
            'filters' => $filter->toArray(),
            'filtered' => $filter->isFiltered(),
            'rejectedFilters' => $request->rejectedFilters(),
            'options' => MonitorPresenter::options(),
            'usage' => $this->limits->usage($identity)->toArray(),
            'can' => ['create' => Gate::allows('create', Monitor::class)],
        ]);
    }

    /**
     * @param int $team Route team.
     * @return Response Empty creation form with defaults raised to the plan's minimum interval.
     */
    public function create(int $team): Response
    {
        Gate::authorize('create', Monitor::class);
        $identity = $this->team($team);
        $usage = $this->limits->usage($identity);

        return Inertia::render('Monitors/Create', [
            'team' => $this->teamSummary($identity),
            'defaults' => MonitorPresenter::defaults($usage->minimumIntervalSeconds),
            'options' => MonitorPresenter::options(),
            'usage' => $usage->toArray(),
        ]);
    }

    /**
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team; foreign IDs are already 404.
     * @return Response Safe detail and the actor's management permissions.
     */
    public function show(int $team, Monitor $monitor): Response
    {
        Gate::authorize('view', $monitor);
        $identity = $this->team($team);

        return Inertia::render('Monitors/Show', [
            'team' => $this->teamSummary($identity),
            'monitor' => $this->presenter->detail($monitor, $this->limits->isWithinQuota($identity, $monitor)),
            'can' => [
                'update' => Gate::allows('update', $monitor),
                'delete' => Gate::allows('delete', $monitor),
                'pause' => Gate::allows('pause', $monitor),
                'resume' => Gate::allows('resume', $monitor),
            ],
        ]);
    }

    /**
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return Response Edit form prefilled from the safe projection only; secrets require explicit replacement.
     */
    public function edit(int $team, Monitor $monitor): Response
    {
        Gate::authorize('update', $monitor);
        $identity = $this->team($team);
        $usage = $this->limits->usage($identity);

        return Inertia::render('Monitors/Edit', [
            'team' => $this->teamSummary($identity),
            'monitor' => $this->presenter->detail($monitor, $this->limits->isWithinQuota($identity, $monitor)),
            'defaults' => MonitorPresenter::defaults($usage->minimumIntervalSeconds),
            'options' => MonitorPresenter::options(),
            'usage' => $usage->toArray(),
        ]);
    }

    /**
     * @param int $team Route team whose membership the middleware has verified.
     * @return Team Team identity, read to resolve its effective plan.
     */
    private function team(int $team): Team
    {
        return Team::query()->findOrFail($team);
    }

    /**
     * @param Team $team Route team.
     * @return array{id: int, name: string} Minimal identity for headings and links.
     */
    private function teamSummary(Team $team): array
    {
        return ['id' => $team->id, 'name' => $team->name];
    }
}
