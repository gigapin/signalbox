<?php

declare(strict_types=1);

/** Select only heartbeat receipts visible at an admitted execution's scheduled reference instant. */

namespace App\Services\Monitoring\Checks;

use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\HeartbeatSnapshot;
use App\Models\MonitorHeartbeat;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** The future runner must revalidate monitor revision and eligibility before requesting this tenant-scoped snapshot. */
final readonly class HeartbeatSnapshotReader
{
    /** @param TenantContext $tenants Previously authorized execution context. */
    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * @param CheckContext $context Admitted scalar execution context, with its scheduled reference slot.
     * @return HeartbeatSnapshot Current generation's latest receipt at or before the slot.
     * @throws InvalidArgumentException When the context does not belong to the active tenant.
     */
    public function read(CheckContext $context): HeartbeatSnapshot
    {
        if ($context->teamId !== $this->tenants->requireTeamId()) {
            throw new InvalidArgumentException('Heartbeat snapshot requires the active tenant.');
        }

        $state = MonitorHeartbeat::query()->where('monitor_id', $context->monitorId)->firstOrFail();
        $received = DB::table('heartbeat_receipts')->where('team_id', $context->teamId)
            ->where('monitor_heartbeat_id', $state->id)->where('generation', $state->generation)
            ->where('received_at', '<=', $context->referenceAt->utc()->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('received_at')->value('received_at');

        return new HeartbeatSnapshot($context->teamId, $context->monitorId, $state->started_at, is_string($received) ? CarbonImmutable::parse($received)->utc() : null);
    }
}
