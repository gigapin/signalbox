<?php

declare(strict_types=1);

/** Send verification codes through the same bounded adapter boundary as incident messages. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Contracts\Notifications\ChannelVerificationSender;
use App\Data\Notifications\ChannelMessage;
use App\Exceptions\ChannelDeliveryFailed;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/** Called after the action commits its code digest; SMS suppression causes the action to withdraw that code. */
final readonly class AdapterChannelVerificationSender implements ChannelVerificationSender
{
    /**
     * @param ChannelAdapterRegistry $adapters Explicit provider strategies.
     * @param Repository $config Verification lifetime shared with the action.
     */
    public function __construct(private ChannelAdapterRegistry $adapters, private Repository $config)
    {
    }

    /**
     * @param string $channelName Recognizable label, included only in recipient text.
     * @param ChannelConfiguration $configuration Current destination and credentials.
     * @param string $code Six-digit plaintext code; never logged or serialized to the queue.
     * @throws ChannelDeliveryFailed On delivery failure or SMS stub suppression.
     */
    public function send(
        #[\SensitiveParameter] string $channelName,
        #[\SensitiveParameter] ChannelConfiguration $configuration,
        #[\SensitiveParameter] string $code,
    ): void {
        $ttl = (int) $this->config->get('notifications.verification_ttl_seconds');
        $message = new ChannelMessage(
            (string) Str::uuid(),
            'channel.verification',
            CarbonImmutable::now('UTC'),
            'Verify your Signalbox notification channel',
            'Your Signalbox verification code for "'.$channelName.'" is '.$code.'. It expires in '.$ttl.' seconds. Enter it in Signalbox to activate this channel.',
            ['code' => $code, 'expires_in_seconds' => $ttl],
        );
        $receipt = $this->adapters->for($configuration->type())->send($configuration, $message);

        if ($receipt->stubbed) {
            throw new ChannelDeliveryFailed('SMS is a recording-only stub and cannot verify a destination.');
        }
    }
}
