<?php

declare(strict_types=1);

/** Record the declared SMS stub without contacting a carrier or logging recipient content. */

namespace App\Services\Notifications\Adapters;

use App\Contracts\Notifications\ChannelAdapter;
use App\Contracts\Notifications\ChannelConfiguration;
use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\ChannelMessage;
use App\Data\Notifications\SmsChannelConfiguration;
use App\Exceptions\ChannelDeliveryFailed;
use App\Services\Security\SecretRedactor;
use Psr\Log\LoggerInterface;

/** A stub is always suppressed; it must never imply verification or successful carrier delivery. */
final readonly class SmsChannelAdapter implements ChannelAdapter
{
    /**
     * @param LoggerInterface $logger Application logger with the global secret-redaction tap.
     * @param SecretRedactor $redactor Additional structured redaction before handing metadata to the logger.
     */
    public function __construct(private LoggerInterface $logger, private SecretRedactor $redactor)
    {
    }

    /**
     * @param ChannelConfiguration $configuration SMS number, deliberately absent from logs.
     * @param ChannelMessage $message Only its UUID and controlled event name enter the stub record.
     * @return ChannelDeliveryReceipt Suppressed receipt; no destination, code or message content is recorded.
     * @throws ChannelDeliveryFailed When called with another family's configuration.
     */
    public function send(#[\SensitiveParameter] ChannelConfiguration $configuration, #[\SensitiveParameter] ChannelMessage $message): ChannelDeliveryReceipt
    {
        if (!$configuration instanceof SmsChannelConfiguration) {
            throw new ChannelDeliveryFailed('The SMS channel configuration is invalid.');
        }

        $this->logger->info('SMS stub: recorded without sending.', $this->redactor->context([
            'message_id' => $message->id,
            'event' => $message->event,
            'channel_type' => 'sms',
        ]));

        return new ChannelDeliveryReceipt(stubbed: true);
    }
}
