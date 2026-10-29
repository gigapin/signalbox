<?php

declare(strict_types=1);

/** Deliver versioned webhook envelopes signed over the timestamp and exact transmitted JSON bytes. */

namespace App\Services\Notifications\Adapters;

use App\Contracts\Notifications\ChannelAdapter;
use App\Contracts\Notifications\ChannelConfiguration;
use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\ChannelMessage;
use App\Data\Notifications\WebhookChannelConfiguration;
use App\Exceptions\ChannelDeliveryFailed;
use App\Services\Notifications\NotificationHttpTransport;
use Carbon\CarbonImmutable;

/** The message UUID remains stable across attempts; timestamp and HMAC are renewed on each attempt. */
final readonly class WebhookChannelAdapter implements ChannelAdapter
{
    /** @param NotificationHttpTransport $http Shared transport refusing redirects and pinning authorized addresses. */
    public function __construct(private NotificationHttpTransport $http)
    {
    }

    /**
     * @param ChannelConfiguration $configuration Endpoint and 64-character hexadecimal secret used as the literal HMAC key.
     * @param ChannelMessage $message Explicit recipient envelope; no model serialization occurs.
     * @return ChannelDeliveryReceipt Acceptance for any 2xx response; response content is discarded.
     * @throws ChannelDeliveryFailed On mismatched configuration, encoding, security or provider failure.
     */
    public function send(#[\SensitiveParameter] ChannelConfiguration $configuration, #[\SensitiveParameter] ChannelMessage $message): ChannelDeliveryReceipt
    {
        if (!$configuration instanceof WebhookChannelConfiguration) {
            throw new ChannelDeliveryFailed('The webhook channel configuration is invalid.');
        }

        $payload = $message->payload();
        // Keep the event field map an object even when there are no additional fields.
        $payload['data'] = (object) $payload['data'];
        $body = $this->http->encode($payload);
        $timestamp = (string) CarbonImmutable::now('UTC')->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $configuration->signingSecret);

        $this->http->post($configuration->url, $body, [
            'X-Signalbox-Id' => $message->id,
            'X-Signalbox-Timestamp' => $timestamp,
            'X-Signalbox-Signature' => 'v1='.$signature,
        ]);

        return new ChannelDeliveryReceipt();
    }
}
