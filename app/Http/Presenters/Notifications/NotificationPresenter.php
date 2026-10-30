<?php

declare(strict_types=1);

/** Project notification channels, escalation policies and delivery logs into safe page props. */

namespace App\Http\Presenters\Notifications;

use App\Actions\Notifications\ManageEscalationPolicies;
use App\Actions\Notifications\ManageNotificationChannels;
use App\Enums\Notifications\ChannelType;
use App\Models\EscalationPolicy;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Services\Notifications\ChannelConfigurationParser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Secrets never leave the server: every member sees a masked hint; managers additionally receive the
 * non-secret values their edit form may prefill. Code digests and expiry internals are reduced to flags.
 */
final readonly class NotificationPresenter
{
    /** @param Repository $config Operational bounds shown to the forms. */
    public function __construct(private Repository $config)
    {
    }

    /**
     * @param NotificationChannel $channel Channel of the active team.
     * @param bool $manager Whether the viewer may edit channels.
     * @param list<string> $usedBy Names of the policies notifying the channel.
     * @param CarbonImmutable $now Current UTC instant.
     * @return array{id: int, type: string, name: string, hint: string, isActive: bool, isStub: bool, verifiedAt: string|null, verificationPending: bool, verificationExpiresAt: string|null, editable: array<string, string>|null, usedBy: list<string>, quietHours: array{start: string, end: string, timezone: string}|null} Safe channel row.
     */
    public function channel(NotificationChannel $channel, bool $manager, array $usedBy, CarbonImmutable $now): array
    {
        $configuration = $channel->configuration();
        $pending = $channel->hasPendingCode($now);
        $hours = $channel->quietHours();

        return [
            'id' => $channel->id,
            'type' => $channel->type->value,
            'name' => $channel->name,
            'hint' => $configuration->hint(),
            'isActive' => $channel->is_active,
            'isStub' => $channel->type->isStub(),
            'verifiedAt' => $channel->verified_at?->toIso8601String(),
            'verificationPending' => $pending,
            'verificationExpiresAt' => $pending ? $channel->verification_expires_at?->toIso8601String() : null,
            'editable' => $manager ? $configuration->editable() : null,
            'usedBy' => $usedBy,
            'quietHours' => $hours === null ? null : [
                'start' => $hours->start,
                'end' => $hours->end,
                'timezone' => $hours->timezone,
            ],
        ];
    }

    /**
     * @param EscalationPolicy $policy Policy of the active team.
     * @param array<int, NotificationChannel> $channels Team channels keyed by identity.
     * @param int $monitorCount Monitors overriding the default with this policy.
     * @return array{id: int, name: string, isDefault: bool, monitorCount: int, steps: list<array{afterMinutes: int, channels: list<array{id: int, name: string, isActive: bool}>}>} Policy with resolved channel labels.
     */
    public function policy(EscalationPolicy $policy, array $channels, int $monitorCount): array
    {
        $steps = [];

        foreach ($policy->steps() as $step) {
            $targets = [];

            foreach ($step->channelIds as $id) {
                if (isset($channels[$id])) {
                    $targets[] = ['id' => $id, 'name' => $channels[$id]->name, 'isActive' => $channels[$id]->is_active];
                }
            }

            $steps[] = ['afterMinutes' => $step->afterMinutes, 'channels' => $targets];
        }

        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'isDefault' => $policy->is_default,
            'monitorCount' => $monitorCount,
            'steps' => $steps,
        ];
    }

    /**
     * @param NotificationLog $log Delivery of the active team, with its channel relation loaded.
     * @return array{id: int, channelName: string|null, channelType: string, kind: string, stepIndex: int|null, status: string, attempts: int, response: string|null, sentAt: string|null, createdAt: string|null} Safe delivery row.
     */
    public function delivery(NotificationLog $log): array
    {
        return [
            'id' => $log->id,
            'channelName' => $log->channel?->name,
            'channelType' => $log->channel_type->value,
            'kind' => $log->kind->value,
            'stepIndex' => $log->step_index,
            'status' => $log->status->value,
            'attempts' => $log->attempts,
            'response' => $log->response,
            'sentAt' => $log->sent_at?->toIso8601String(),
            'createdAt' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param Monitor $monitor Monitor of the active team.
     * @param list<EscalationPolicy> $policies Team policies ordered by name.
     * @return array{policyId: int|null, effective: array{id: int, name: string, source: string}|null, options: list<array{id: int, name: string, isDefault: bool}>} Monitor escalation choice and its effect.
     */
    public function monitorEscalation(Monitor $monitor, array $policies): array
    {
        $options = [];
        $override = null;
        $default = null;

        foreach ($policies as $policy) {
            $options[] = ['id' => $policy->id, 'name' => $policy->name, 'isDefault' => $policy->is_default];

            if ($policy->id === $monitor->escalation_policy_id) {
                $override = $policy;
            }

            if ($policy->is_default) {
                $default = $policy;
            }
        }

        $effective = null;

        if ($override !== null) {
            $effective = ['id' => $override->id, 'name' => $override->name, 'source' => 'override'];
        } elseif ($default !== null) {
            $effective = ['id' => $default->id, 'name' => $default->name, 'source' => 'default'];
        }

        return ['policyId' => $override?->id, 'effective' => $effective, 'options' => $options];
    }

    /** @return list<array{value: string, label: string, isStub: bool, secretFields: list<string>}> Channel families offered by the creation form. */
    public static function channelTypes(): array
    {
        return array_map(static fn (ChannelType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'isStub' => $type->isStub(),
            'secretFields' => ChannelConfigurationParser::SECRET_FIELDS[$type->value] ?? [],
        ], ChannelType::cases());
    }

    /** @return array{channelsPerTeam: int, policiesPerTeam: int, stepsPerPolicy: int, channelsPerStep: int, maxStepDelayMinutes: int, nameMaxLength: int, codeLength: int} Bounds the forms apply before the server applies them again. */
    public function limits(): array
    {
        return [
            'channelsPerTeam' => (int) $this->config->get('notifications.channels_per_team'),
            'policiesPerTeam' => (int) $this->config->get('notifications.policies_per_team'),
            'stepsPerPolicy' => (int) $this->config->get('notifications.steps_per_policy'),
            'channelsPerStep' => (int) $this->config->get('notifications.channels_per_step'),
            'maxStepDelayMinutes' => (int) $this->config->get('notifications.max_step_delay_minutes'),
            'nameMaxLength' => min(ManageNotificationChannels::NAME_MAX_LENGTH, ManageEscalationPolicies::NAME_MAX_LENGTH),
            'codeLength' => 6,
        ];
    }
}
