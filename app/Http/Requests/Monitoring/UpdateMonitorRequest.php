<?php

declare(strict_types=1);

/** Authorize a bound tenant monitor before loading its current configuration for partial validation. */

namespace App\Http\Requests\Monitoring;

use App\Data\Monitoring\MonitorInput;
use App\Models\Monitor;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Binding, context and policy are separate checks; future actions still recheck them under lock. */
final class UpdateMonitorRequest extends MonitorInputRequest
{
    /** @return bool Whether the bound record belongs to the active team and the actor may update it. */
    public function authorize(): bool
    {
        $teamId = $this->authorizedTeamId();
        $monitor = $this->route('monitor');

        return $teamId !== null && $monitor instanceof Monitor && $monitor->exists
            && $monitor->team_id === $teamId && Gate::allows('update', $monitor);
    }

    /**
     * @return MonitorInput Authorized snapshot used only for this validation pass.
     * @throws LogicException When called without an actual bound monitor.
     */
    protected function currentInput(): MonitorInput
    {
        $monitor = $this->route('monitor');

        if (!$monitor instanceof Monitor) {
            throw new LogicException('Monitor update validation requires a bound monitor.');
        }

        return MonitorInput::fromMonitor($monitor);
    }
}
