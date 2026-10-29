<?php

declare(strict_types=1);

/** Resolve the explicit strategy for every configured channel family. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\ChannelAdapter;
use App\Enums\Notifications\ChannelType;
use App\Services\Notifications\Adapters\EmailChannelAdapter;
use App\Services\Notifications\Adapters\SlackChannelAdapter;
use App\Services\Notifications\Adapters\SmsChannelAdapter;
use App\Services\Notifications\Adapters\TelegramChannelAdapter;
use App\Services\Notifications\Adapters\WebhookChannelAdapter;

/** Stateless registry shared by verification and future incident jobs; no transport logic belongs here. */
final readonly class ChannelAdapterRegistry
{
    /**
     * @param EmailChannelAdapter $email Bounded SMTP strategy.
     * @param SlackChannelAdapter $slack Incoming webhook strategy.
     * @param TelegramChannelAdapter $telegram Bot sendMessage strategy.
     * @param WebhookChannelAdapter $webhook Signed JSON strategy.
     * @param SmsChannelAdapter $sms Declared recording-only stub.
     */
    public function __construct(
        private EmailChannelAdapter $email,
        private SlackChannelAdapter $slack,
        private TelegramChannelAdapter $telegram,
        private WebhookChannelAdapter $webhook,
        private SmsChannelAdapter $sms,
    ) {
    }

    /**
     * @param ChannelType $type Validated family from the persisted channel configuration.
     * @return ChannelAdapter Exactly one strategy; every enum case must be handled explicitly.
     */
    public function for(ChannelType $type): ChannelAdapter
    {
        return match ($type) {
            ChannelType::Email => $this->email,
            ChannelType::Slack => $this->slack,
            ChannelType::Telegram => $this->telegram,
            ChannelType::Webhook => $this->webhook,
            ChannelType::Sms => $this->sms,
        };
    }
}
