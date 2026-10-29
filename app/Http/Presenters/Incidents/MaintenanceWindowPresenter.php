<?php

declare(strict_types=1);

/** Build deliberate Inertia projections of maintenance windows with their phase relative to now. */

namespace App\Http\Presenters\Incidents;

use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;

/**
 * The phase tells the interface which change ManageMaintenanceWindows admits: upcoming windows can be edited
 * or deleted, ongoing ones only ended early, finished ones not at all. The action decides again under lock.
 */
final readonly class MaintenanceWindowPresenter
{
    /**
     * @param MaintenanceWindow $window Window of the active team.
     * @param CarbonImmutable $now Reference instant shared by every window of the page.
     * @return array{id: int, startsAt: string, endsAt: string, reason: string, phase: string}
     *         Half-open UTC interval, reason and `upcoming`, `ongoing` or `finished`.
     */
    public function window(MaintenanceWindow $window, CarbonImmutable $now): array
    {
        return [
            'id' => $window->id,
            'startsAt' => $window->starts_at->utc()->toIso8601String(),
            'endsAt' => $window->ends_at->utc()->toIso8601String(),
            'reason' => $window->reason,
            'phase' => $this->phase($window, $now),
        ];
    }

    /**
     * @param MaintenanceWindow $window Window to classify.
     * @param CarbonImmutable $now Reference instant.
     * @return string `upcoming` before the start, `ongoing` in [start, end), `finished` from the end.
     */
    private function phase(MaintenanceWindow $window, CarbonImmutable $now): string
    {
        if ($window->starts_at->isAfter($now)) {
            return 'upcoming';
        }

        if ($window->ends_at->isAfter($now)) {
            return 'ongoing';
        }

        return 'finished';
    }
}
