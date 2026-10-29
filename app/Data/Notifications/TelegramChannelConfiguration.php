<?php

declare(strict_types=1);

/** Hold the bot credential and target chat of a Telegram channel. */

namespace App\Data\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Enums\Notifications\ChannelType;

/** The bot token is secret; the chat identity is not and may be shown to managers. */
final readonly class TelegramChannelConfiguration implements ChannelConfiguration
{
    /**
     * @param string $botToken Bot API token `<bot id>:<secret>`.
     * @param string $chatId Numeric chat identity, possibly negative, or a public `@channel` name.
     */
    public function __construct(public string $botToken, public string $chatId)
    {
    }

    /** @return ChannelType Always Telegram. */
    public function type(): ChannelType
    {
        return ChannelType::Telegram;
    }

    /** @return array{bot_token: string, chat_id: string} Persisted token and chat. */
    public function toArray(): array
    {
        return ['bot_token' => $this->botToken, 'chat_id' => $this->chatId];
    }

    /** @return string Chat identity with only its last four characters visible. */
    public function hint(): string
    {
        return 'Chat …'.substr($this->chatId, -4);
    }

    /** @return array{chat_id: string} The chat identity; the token is never prefilled. */
    public function editable(): array
    {
        return ['chat_id' => $this->chatId];
    }

    /**
     * @param ChannelConfiguration $other Configuration compared field by field.
     * @return bool Whether both use the same bot and chat.
     */
    public function sameAs(ChannelConfiguration $other): bool
    {
        return $other instanceof self && hash_equals($other->botToken, $this->botToken) && $other->chatId === $this->chatId;
    }
}
