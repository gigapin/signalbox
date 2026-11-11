<?php

declare(strict_types=1);

/** Translate browser monitor mutations into lock-aware domain actions. */

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ManageMonitors;
use App\Http\Controllers\Controller;
use App\Http\Navigation\MonitorListReturn;
use App\Http\Requests\Monitoring\StoreMonitorRequest;
use App\Http\Requests\Monitoring\UpdateMonitorRequest;
use App\Models\Monitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creation lands on the new monitor's page and deletion on the list, since the previous page would be the
 * form or a now-missing detail; updates return to detail and scheduling transitions redirect back.
 * Monitor list return hints are reconstructed from query input within the authorized team.
 * Validation and limit refusals surface as session errors without echoing submitted input.
 */
final class MonitorController extends Controller
{
    /**
     * @param ManageMonitors $monitors Transactional monitor transitions.
     * @param MonitorListReturn $returns Normalize untrusted list navigation hints independently of domain input.
     */
    public function __construct(
        private readonly ManageMonitors $monitors,
        private readonly MonitorListReturn $returns,
    ) {
    }

    /**
     * @param StoreMonitorRequest $request Authorized and validated creation input.
     * @param int $team Route team, already established as tenant context.
     * @return RedirectResponse New monitor's detail page with `monitor-created` status.
     */
    public function store(StoreMonitorRequest $request, int $team): RedirectResponse
    {
        $monitor = $this->monitors->create($this->authenticatedUser($request), $team, $request->monitorInput()->patch->values);

        return redirect()
            ->to($this->detailDestination($request, $team, $monitor->id))
            ->with('status', 'monitor-created');
    }

    /**
     * @param UpdateMonitorRequest $request Authorized and validated explicit patch.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Monitor detail with normalized list context and `monitor-updated` status.
     */
    public function update(UpdateMonitorRequest $request, int $team, Monitor $monitor): RedirectResponse
    {
        $this->monitors->update($this->authenticatedUser($request), $team, $monitor->id, $request->monitorInput()->patch->values);

        return redirect()->to($this->detailDestination($request, $team, $monitor->id))->with('status', 'monitor-updated');
    }

    /**
     * @param Request $request Verified browser identity.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Team monitor list with `monitor-deleted` status.
     */
    public function destroy(Request $request, int $team, Monitor $monitor): RedirectResponse
    {
        $this->monitors->delete($this->authenticatedUser($request), $team, $monitor->id);

        return redirect()
            ->to($this->returns->resolve($request, $team))
            ->with('status', 'monitor-deleted');
    }

    /**
     * @param Request $request Mutation request carrying an optional query-only navigation hint.
     * @param int $team Authorized route team.
     * @param int $monitorId Persisted monitor identity.
     * @return string Internal detail destination with only a canonical same-team list return.
     */
    private function detailDestination(Request $request, int $team, int $monitorId): string
    {
        $detail = '/teams/'.$team.'/monitors/'.$monitorId;
        $list = $this->returns->resolve($request, $team);

        if ($list === '/teams/'.$team.'/monitors') {
            return $detail;
        }

        return $detail.'?'.http_build_query(['returnTo' => $list], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param Request $request Verified browser identity.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Previous page with `monitor-paused` status.
     */
    public function pause(Request $request, int $team, Monitor $monitor): RedirectResponse
    {
        $this->monitors->pause($this->authenticatedUser($request), $team, $monitor->id);

        return back()->with('status', 'monitor-paused');
    }

    /**
     * @param Request $request Verified browser identity.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Previous page with `monitor-resumed` status.
     */
    public function resume(Request $request, int $team, Monitor $monitor): RedirectResponse
    {
        $this->monitors->resume($this->authenticatedUser($request), $team, $monitor->id);

        return back()->with('status', 'monitor-resumed');
    }
}
