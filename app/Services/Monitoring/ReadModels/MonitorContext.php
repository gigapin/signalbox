<?php

declare(strict_types=1);

/** Read observed certificate provenance and effective notification targets without credentials. */

namespace App\Services\Monitoring\ReadModels;

use App\Enums\Monitoring\MonitorType;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Services\Notifications\EscalationPolicyResolver;
use Carbon\CarbonImmutable;

/** A cached expiry is never presented as a fresh certificate observation merely because another check ran. */
final readonly class MonitorContext
{
    /** @param EscalationPolicyResolver $policies Existing override/default/none authority. */
    public function __construct(private EscalationPolicyResolver $policies)
    {
    }

    /**
     * @param Monitor $monitor Authorized route monitor.
     * @return array<string, mixed> Non-applicable/missing/stale/current certificate with retained, revision-matching provenance.
     */
    public function certificate(Monitor $monitor): array
    {
        if ($monitor->type !== MonitorType::Tls) {
            return ['state' => 'not_applicable', 'expiresAt' => null, 'observedAt' => null];
        }
        $now = CarbonImmutable::now('UTC');
        $expiry = $monitor->ssl_expires_at;
        if ($expiry === null) {
            return ['state' => 'missing', 'expiresAt' => null, 'observedAt' => null];
        }
        $observation = CheckResult::query()
            ->where('team_id', $monitor->team_id)->where('monitor_id', $monitor->id)
            ->where('monitor_revision', $monitor->execution_revision)
            ->where('kind', 'observed')->where('tls_expires_at', $expiry->toISOString())
            ->where('slot_at', '>=', $now->subDays(30)->toISOString())->where('slot_at', '<=', $now->toISOString())
            ->where('recorded_at', '<=', $now->toISOString())->where('checked_at', '<=', $now->toISOString())
            ->orderByDesc('slot_at')->orderByDesc('recorded_at')->orderByDesc('execution_id')
            ->first(['checked_at']);
        $at = $observation?->checked_at;
        $current = $at !== null && !$at->isBefore($now->subSeconds(2 * $monitor->interval_seconds));

        return ['state' => $current ? 'current' : 'stale', 'expiresAt' => $expiry->toISOString(), 'observedAt' => $at?->toISOString()];
    }

    /**
     * @param Monitor $monitor Authorized route monitor.
     * @return array<string, mixed>|null Effective policy and safe target labels/flags; never promises delivery.
     */
    public function notifications(Monitor $monitor): ?array
    {
        $policy = $this->policies->forMonitor($monitor);
        if ($policy === null) {
            return null;
        }
        $channels = NotificationChannel::query()->where('team_id', $monitor->team_id)
            ->whereIn('id', $policy->channelIds())
            ->get(['id', 'name', 'type', 'is_active', 'verified_at'])->keyBy('id');
        $steps = [];
        foreach ($policy->steps() as $step) {
            $targets = [];
            foreach ($step->channelIds as $id) {
                $channel = $channels->get($id);
                if ($channel !== null) {
                    $targets[] = ['id' => $id, 'name' => $channel->name, 'type' => $channel->type->value,
                        'isActive' => $channel->is_active, 'isVerified' => $channel->verified_at !== null, 'isStub' => $channel->type->isStub()];
                }
            }
            $steps[] = ['afterMinutes' => $step->afterMinutes, 'channels' => $targets];
        }

        return ['id' => $policy->id, 'name' => $policy->name, 'source' => $monitor->escalation_policy_id === $policy->id ? 'override' : 'default', 'steps' => $steps];
    }
}
