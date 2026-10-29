<?php

declare(strict_types=1);

/** Post a plain-text Slack message without formatting, mentions or link previews. */

namespace App\Services\Notifications\Adapters;

use App\Contracts\Notifications\ChannelAdapter;
use App\Contracts\Notifications\ChannelConfiguration;
use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\ChannelMessage;
use App\Data\Notifications\SlackChannelConfiguration;
use App\Exceptions\ChannelDeliveryFailed;
use App\Services\Notifications\NotificationHttpTransport;

/** A Slack incoming-webhook URL is a credential and must never appear in diagnostics. */
final readonly class SlackChannelAdapter implements ChannelAdapter
{
    /** @param NotificationHttpTransport $http Shared pinned HTTPS transport with bounded time and response bytes. */
    public function __construct(private NotificationHttpTransport $http)
    {
    }

    /**
     * @param ChannelConfiguration $configuration Slack incoming-webhook credential.
     * @param ChannelMessage $message Plain text capped below Slack's section limit.
     * @return ChannelDeliveryReceipt Acceptance only when Slack returns HTTP 200 and plain-text ok.
     * @throws ChannelDeliveryFailed On transport errors, mismatched configuration or provider refusal.
     */
    public function send(#[\SensitiveParameter] ChannelConfiguration $configuration, #[\SensitiveParameter] ChannelMessage $message): ChannelDeliveryReceipt
    {
        if (!$configuration instanceof SlackChannelConfiguration) {
            throw new ChannelDeliveryFailed('The Slack channel configuration is invalid.');
        }

        $body = $this->http->encode([
            'text' => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $message->text),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => ['type' => 'plain_text', 'text' => $message->text, 'emoji' => false],
                ],
            ],
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]);
        $response = $this->http->post($configuration->webhookUrl, $body);

        if ($response->status() !== 200 || trim($response->body()) !== 'ok') {
            throw new ChannelDeliveryFailed('Slack did not acknowledge the notification.');
        }

        return new ChannelDeliveryReceipt();
    }
}
