<?php

declare(strict_types=1);

/** Build synthetic notification channels only within an explicitly established tenant context. */

namespace Database\Factories;

use App\Enums\Notifications\ChannelType;
use App\Models\NotificationChannel;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationChannel> Unverified email channels unless a state says otherwise; every destination is synthetic. */
final class NotificationChannelFactory extends Factory
{
    /** @return array<string, mixed> Inactive email channel to a reserved example domain. */
    public function definition(): array
    {
        return [
            'team_id' => app(TenantContext::class)->requireTeamId(),
            'type' => ChannelType::Email,
            'name' => 'On-call email',
            'config_json' => ['address' => 'oncall-'.fake()->unique()->numberBetween(1, 999_999).'@example.test'],
        ];
    }

    /** @return static Slack channel with a synthetic webhook. */
    public function slack(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Slack,
            'name' => 'Ops Slack',
            'config_json' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/'.bin2hex(random_bytes(12))],
        ]);
    }

    /** @return static Telegram channel with a synthetic bot token. */
    public function telegram(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Telegram,
            'name' => 'Ops Telegram',
            'config_json' => ['bot_token' => '123456:'.bin2hex(random_bytes(16)), 'chat_id' => '-1001234567890'],
        ]);
    }

    /** @return static Signed webhook with a fresh signing key. */
    public function webhook(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Webhook,
            'name' => 'Ops webhook',
            'config_json' => ['url' => 'https://hooks.example.test/signalbox', 'signing_secret' => bin2hex(random_bytes(32))],
        ]);
    }

    /** @return static SMS stub channel. */
    public function sms(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Sms,
            'name' => 'On-call phone',
            'config_json' => ['phone' => '+15550001234'],
        ]);
    }

    /** @return static Channel verified and active one hour ago. */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'verified_at' => CarbonImmutable::now('UTC')->subHour(),
        ]);
    }
}
