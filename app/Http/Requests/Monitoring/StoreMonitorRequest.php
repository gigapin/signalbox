<?php

declare(strict_types=1);

/** Require explicit create authority before inspecting a new monitor configuration. */

namespace App\Http\Requests\Monitoring;

use App\Models\Monitor;
use Illuminate\Support\Facades\Gate;

/** MonitorPolicy grants owners, admins and members; ManageMonitors rechecks it and plan limits under lock. */
final class StoreMonitorRequest extends MonitorInputRequest
{
    /** @return bool Whether a verified contextual actor has the explicit monitor create ability. */
    public function authorize(): bool
    {
        return $this->authorizedTeamId() !== null && Gate::allows('create', Monitor::class);
    }
}
