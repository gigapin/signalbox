<?php

declare(strict_types=1);

/** Share the authorization of manual incident changes submitted from the browser. */

namespace App\Http\Requests\Incidents;

use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization runs before validation, so a viewer receives 403 whatever the payload. ManageIncidents
 * re-runs IncidentPolicy::update under the Team → Monitor → Incident locks.
 */
abstract class IncidentChangeRequest extends FormRequest
{
    /** @return bool Whether the actor may manage the route incident, bound inside the route team. */
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof Incident && Gate::allows('update', $incident);
    }
}
