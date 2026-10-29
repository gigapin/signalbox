<?php

declare(strict_types=1);

/** Translate browser incident changes into lock-aware domain actions. */

namespace App\Http\Controllers\Incidents;

use App\Actions\Incidents\ManageIncidents;
use App\Exceptions\IncidentChangeRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\ChangeIncidentSeverityRequest;
use App\Http\Requests\Incidents\DeclareRootCauseRequest;
use App\Http\Requests\Incidents\PostIncidentUpdateRequest;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Every change redirects back to the incident page. Lifecycle refusals from ManageIncidents become a session
 * error on the `incident` key, shown at the top of the page; field syntax errors come from the Form Requests.
 */
final class IncidentController extends Controller
{
    /** @param ManageIncidents $incidents Transactional manual incident changes. */
    public function __construct(private readonly ManageIncidents $incidents)
    {
    }

    /**
     * @param Request $request Verified browser identity.
     * @param int $team Route team.
     * @param Incident $incident Incident bound inside the route team.
     * @return RedirectResponse Previous page with `incident-acknowledged` status.
     */
    public function acknowledge(Request $request, int $team, Incident $incident): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): Incident => $this->incidents->acknowledge($actor, $team, $incident->id));

        return back()->with('status', 'incident-acknowledged');
    }

    /**
     * @param PostIncidentUpdateRequest $request Authorized and validated entry.
     * @param int $team Route team.
     * @param Incident $incident Incident bound inside the route team.
     * @return RedirectResponse Previous page with `incident-update-posted` status.
     */
    public function storeUpdate(PostIncidentUpdateRequest $request, int $team, Incident $incident): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): IncidentUpdate => $this->incidents->postUpdate($actor, $team, $incident->id, $request->status(), $request->message()));

        return back()->with('status', 'incident-update-posted');
    }

    /**
     * @param ChangeIncidentSeverityRequest $request Authorized and validated severity.
     * @param int $team Route team.
     * @param Incident $incident Incident bound inside the route team.
     * @return RedirectResponse Previous page with `incident-severity-changed` status.
     */
    public function severity(ChangeIncidentSeverityRequest $request, int $team, Incident $incident): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): Incident => $this->incidents->changeSeverity($actor, $team, $incident->id, $request->severity()));

        return back()->with('status', 'incident-severity-changed');
    }

    /**
     * @param DeclareRootCauseRequest $request Authorized and validated note, or null to clear it.
     * @param int $team Route team.
     * @param Incident $incident Incident bound inside the route team.
     * @return RedirectResponse Previous page with `incident-root-cause-saved` status.
     */
    public function rootCause(DeclareRootCauseRequest $request, int $team, Incident $incident): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): Incident => $this->incidents->declareRootCause($actor, $team, $incident->id, $request->note()));

        return back()->with('status', 'incident-root-cause-saved');
    }

    /**
     * @param Request $request Verified browser identity.
     * @param int $team Route team.
     * @param Incident $incident Incident bound inside the route team.
     * @param IncidentUpdate $update Entry bound inside that incident; another incident's entry is a 404.
     * @return RedirectResponse Previous page with `incident-update-published` status.
     */
    public function publish(Request $request, int $team, Incident $incident, IncidentUpdate $update): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): IncidentUpdate => $this->incidents->publish($actor, $team, $update->id));

        return back()->with('status', 'incident-update-published');
    }

    /**
     * @template TResult
     * @param Closure(): TResult $change Domain change that may refuse the request.
     * @return TResult Change result.
     * @throws ValidationException When the lifecycle or timeline rules refuse the change.
     */
    private function rejectable(Closure $change): mixed
    {
        try {
            return $change();
        } catch (IncidentChangeRejected $exception) {
            throw ValidationException::withMessages(['incident' => $exception->getMessage()]);
        }
    }
}
