<?php

declare(strict_types=1);

/** Answer whether a monitor is under planned maintenance at an instant. */

namespace App\Services\Incidents;

use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;

/** Reads through the tenant scope; callers run inside the monitor's team context. */
final class MaintenanceSchedule
{
    /**
     * @param int $monitorId Monitor of the active team.
     * @param CarbonImmutable $at Instant to test, such as an evaluated slot.
     * @return bool Whether any window covers the instant in [starts_at, ends_at).
     */
    public function activeAt(int $monitorId, CarbonImmutable $at): bool
    {
        return MaintenanceWindow::query()
            ->where('monitor_id', $monitorId)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->exists();
    }
}
