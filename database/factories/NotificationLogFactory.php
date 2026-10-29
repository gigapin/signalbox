<?php

declare(strict_types=1);

/** Build synthetic delivery log rows only within an explicitly established tenant context. */

namespace Database\Factories;

use App\Enums\Notifications\ChannelType;
use App\Enums\Notifications\DeliveryStatus;
use App\Enums\Notifications\NotificationKind;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationLog> Sent opening notifications unless a test overrides the fields. */
final class NotificationLogFactory extends Factory
{
    /** @return array<string, mixed> First-step email delivery accepted now. */
    public function definition(): array
    {
        return [
            'team_id' => app(TenantContext::class)->requireTeamId(),
            'incident_id' => Incident::factory(),
            'channel_id' => NotificationChannel::factory(),
            'channel_type' => ChannelType::Email,
            'kind' => NotificationKind::Opened,
            'step_index' => 0,
            'status' => DeliveryStatus::Sent,
            'attempts' => 1,
            'response' => 'Accepted.',
            'dedupe_key' => 'synthetic:'.fake()->unique()->uuid(),
            'sent_at' => CarbonImmutable::now('UTC'),
        ];
    }
}
