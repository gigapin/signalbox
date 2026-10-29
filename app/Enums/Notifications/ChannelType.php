<?php

declare(strict_types=1);

/** Enumerate the notification channel families a team can configure. */

namespace App\Enums\Notifications;

/** Values match the `notification_channels.type` check constraint. SMS is a declared stub without a provider. */
enum ChannelType: string
{
    /** Email to one address. */
    case Email = 'email';

    /** Slack incoming webhook. */
    case Slack = 'slack';

    /** Telegram bot message to one chat. */
    case Telegram = 'telegram';

    /** Signed HTTPS POST to a customer endpoint. */
    case Webhook = 'webhook';

    /** Text message; a stub that never reaches a real carrier. */
    case Sms = 'sms';

    /** @return bool Whether the channel is a declared stub with no production delivery. */
    public function isStub(): bool
    {
        return $this === self::Sms;
    }

    /** @return string Human-readable family name for pages and messages. */
    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Slack => 'Slack',
            self::Telegram => 'Telegram',
            self::Webhook => 'Webhook',
            self::Sms => 'SMS (stub)',
        };
    }
}
