<?php

declare(strict_types=1);

/** Translate browser monitor mutations into lock-aware domain actions. */

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ManageMonitors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreMonitorRequest;
use App\Http\Requests\Monitoring\UpdateMonitorRequest;
use App\Models\Monitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Responses redirect back with a status flash; list, detail and form pages arrive in M2.05.
 * Validation and limit refusals surface as session errors without echoing submitted input.
 */
final class MonitorController extends Controller
{
    /** @param ManageMonitors $monitors Transactional monitor transitions. */
    public function __construct(private readonly ManageMonitors $monitors)
    {
    }

    /**
     * @param StoreMonitorRequest $request Authorized and validated creation input.
     * @param int $team Route team, already established as tenant context.
     * @return RedirectResponse Previous page with `monitor-created` status.
     */
    public function store(StoreMonitorRequest $request, int $team): RedirectResponse
    {
        $this->monitors->create($this->authenticatedUser($request), $team, $request->monitorInput()->patch->values);

        return back()->with('status', 'monitor-created');
    }

    /**
     * @param UpdateMonitorRequest $request Authorized and validated explicit patch.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Previous page with `monitor-updated` status.
     */
    public function update(UpdateMonitorRequest $request, int $team, Monitor $monitor): RedirectResponse
    {
        $this->monitors->update($this->authenticatedUser($request), $team, $monitor->id, $request->monitorInput()->patch->values);

        return back()->with('status', 'monitor-updated');
    }

    /**
     * @param Request $request Verified browser identity.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Previous page with `monitor-deleted` status.
     */
    public function destroy(Request $request, int $team, Monitor $monitor): RedirectResponse
    {
        $this->monitors->delete($this->authenticatedUser($request), $team, $monitor->id);

        return back()->with('status', 'monitor-deleted');
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
