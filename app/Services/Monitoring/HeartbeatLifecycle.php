<?php

declare(strict_types=1);

/** Keep heartbeat arming state aligned with authorized monitor lifecycle transitions. */

namespace App\Services\Monitoring;

use App\Enums\Monitoring\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use Carbon\CarbonImmutable;

/** Callers hold Team then Monitor locks; timing changes start a new generation without rotating its credential. */
final class HeartbeatLifecycle
{
    /**
     * @param Monitor $monitor Fresh locked monitor after its authorized mutation.
     * @param bool $restart Whether a real resume or timing edit resets the rolling window.
     * @return MonitorHeartbeat|null Current state, absent for non-heartbeat variants.
     */
    public function synchronize(Monitor $monitor, bool $restart = false): ?MonitorHeartbeat
    {
        $state = MonitorHeartbeat::query()->where('monitor_id', $monitor->id)->lockForUpdate()->first();

        if ($monitor->type !== MonitorType::Heartbeat) {
            $state?->delete();

            return null;
        }

        if ($state === null) {
            $state = new MonitorHeartbeat();
            $state->forceFill(['monitor_id' => $monitor->id, 'generation' => 1, 'started_at' => CarbonImmutable::now('UTC')])->save();
        } elseif ($restart) {
            $state->forceFill([
                'generation' => $state->generation + 1,
                'started_at' => CarbonImmutable::now('UTC'),
                'last_received_at' => null,
            ])->save();
        }

        return $state;
    }
}
