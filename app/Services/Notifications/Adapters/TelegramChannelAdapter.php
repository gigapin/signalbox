<?php

declare(strict_types=1);

/** Deliver Telegram bot messages with literal text and link previews disabled. */

namespace App\Services\Notifications\Adapters;

use App\Contracts\Notifications\ChannelAdapter;
use App\Contracts\Notifications\ChannelConfiguration;
use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\ChannelMessage;
use App\Data\Notifications\TelegramChannelConfiguration;
use App\Exceptions\ChannelDeliveryFailed;
use App\Services\Notifications\NotificationHttpTransport;

/** The token occurs in Telegram's required request path; never retain a raw transport exception. */
final readonly class TelegramChannelAdapter implements ChannelAdapter
{
    /** @param NotificationHttpTransport $http Shared guarded transport; the Telegram host is fixed by this adapter. */
    public function __construct(private NotificationHttpTransport $http)
    {
    }

    /**
     * @param ChannelConfiguration $configuration Validated bot token and chat identity.
     * @param ChannelMessage $message Literal text without parse_mode or entity expansion.
     * @return ChannelDeliveryReceipt Acceptance only for a successful HTTP response with JSON ok exactly true.
     * @throws ChannelDeliveryFailed On transport errors, mismatched configuration or an invalid acknowledgement.
     */
    public function send(#[\SensitiveParameter] ChannelConfiguration $configuration, #[\SensitiveParameter] ChannelMessage $message): ChannelDeliveryReceipt
    {
        if (!$configuration instanceof TelegramChannelConfiguration) {
            throw new ChannelDeliveryFailed('The Telegram channel configuration is invalid.');
        }

        $body = $this->http->encode([
            'chat_id' => $configuration->chatId,
            'text' => $message->text,
            'link_preview_options' => ['is_disabled' => true],
        ]);
        $response = $this->http->post('https://api.telegram.org/bot'.$configuration->botToken.'/sendMessage', $body);

        if ($response->status() !== 200 || $response->json('ok') !== true) {
            throw new ChannelDeliveryFailed('Telegram did not acknowledge the notification.');
        }

        return new ChannelDeliveryReceipt();
    }
}
