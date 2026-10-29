<?php

declare(strict_types=1);

/** Answer whether a monitor is under planned maintenance at an instant. */

namespace App\Services\Incidents;

use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/** Reads through the tenant scope; callers run inside the monitor's team context. */
final class MaintenanceSchedule
{
    /** @var int Most upcoming or ongoing windows listed for one monitor; later ones appear once earlier ones end. */
    public const int CURRENT_LIMIT = 50;

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

    /**
     * @param int $monitorId Monitor of the active team.
     * @param CarbonImmutable $now Reference instant.
     * @return Collection<int, MaintenanceWindow> Ongoing and upcoming windows, earliest start first, at most CURRENT_LIMIT.
     */
    public function current(int $monitorId, CarbonImmutable $now): Collection
    {
        return MaintenanceWindow::query()
            ->where('monitor_id', $monitorId)
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(self::CURRENT_LIMIT)
            ->get();
    }

    /**
     * @param int $monitorId Monitor of the active team.
     * @param CarbonImmutable $now Reference instant.
     * @param int $limit Maximum number of windows returned.
     * @return Collection<int, MaintenanceWindow> Finished windows, latest end first.
     */
    public function finished(int $monitorId, CarbonImmutable $now, int $limit): Collection
    {
        return MaintenanceWindow::query()
            ->where('monitor_id', $monitorId)
            ->where('ends_at', '<=', $now)
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
