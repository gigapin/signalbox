<?php

declare(strict_types=1);

/** Translate browser maintenance window changes into lock-aware domain actions. */

namespace App\Http\Controllers\Incidents;

use App\Actions\Incidents\ManageMaintenanceWindows;
use App\Exceptions\MaintenanceWindowRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\MaintenanceWindowRequest;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Windows are managed from the monitor page, so every change redirects back. Refusals that protect the past
 * become a session error on the `maintenance` key.
 */
final class MaintenanceWindowController extends Controller
{
    /** @param ManageMaintenanceWindows $windows Transactional maintenance changes. */
    public function __construct(private readonly ManageMaintenanceWindows $windows)
    {
    }

    /**
     * @param MaintenanceWindowRequest $request Authorized and syntactically valid window.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @return RedirectResponse Previous page with `maintenance-scheduled` status.
     */
    public function store(MaintenanceWindowRequest $request, int $team, Monitor $monitor): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): MaintenanceWindow => $this->windows->schedule($actor, $team, $monitor->id, $request->startsAt(), $request->endsAt(), $request->reason()));

        return back()->with('status', 'maintenance-scheduled');
    }

    /**
     * @param MaintenanceWindowRequest $request Authorized and syntactically valid replacement.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @param MaintenanceWindow $maintenanceWindow Window bound inside that monitor; another monitor's window is a 404.
     * @return RedirectResponse Previous page with `maintenance-rescheduled` status.
     */
    public function update(MaintenanceWindowRequest $request, int $team, Monitor $monitor, MaintenanceWindow $maintenanceWindow): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): MaintenanceWindow => $this->windows->reschedule($actor, $team, $maintenanceWindow->id, $request->startsAt(), $request->endsAt(), $request->reason()));

        return back()->with('status', 'maintenance-rescheduled');
    }

    /**
     * @param Request $request Verified browser identity.
     * @param int $team Route team.
     * @param Monitor $monitor Monitor bound inside the route team.
     * @param MaintenanceWindow $maintenanceWindow Window bound inside that monitor.
     * @return RedirectResponse Previous page with `maintenance-deleted` for a future window or `maintenance-ended` for an ongoing one.
     */
    public function destroy(Request $request, int $team, Monitor $monitor, MaintenanceWindow $maintenanceWindow): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $shortened = $this->rejectable(fn (): ?MaintenanceWindow => $this->windows->cancel($actor, $team, $maintenanceWindow->id));

        return back()->with('status', $shortened === null ? 'maintenance-deleted' : 'maintenance-ended');
    }

    /**
     * @template TResult
     * @param Closure(): TResult $change Domain change that may refuse the request.
     * @return TResult Change result.
     * @throws ValidationException When the change would rewrite the past or is otherwise invalid.
     */
    private function rejectable(Closure $change): mixed
    {
        try {
            return $change();
        } catch (MaintenanceWindowRejected $exception) {
            throw ValidationException::withMessages(['maintenance' => $exception->getMessage()]);
        }
    }
}
