<?php

declare(strict_types=1);

/** Require explicit create authority before inspecting a new monitor configuration. */

namespace App\Http\Requests\Monitoring;

use App\Models\Monitor;
use Illuminate\Support\Facades\Gate;

/** Monitor policies are introduced by M2.04; absent policy is a denial rather than an implicit grant. */
final class StoreMonitorRequest extends MonitorInputRequest
{
    /** @return bool Whether a verified contextual actor has the explicit monitor create ability. */
    public function authorize(): bool
    {
        return $this->authorizedTeamId() !== null && Gate::allows('create', Monitor::class);
    }
}
