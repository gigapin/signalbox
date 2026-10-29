<?php

declare(strict_types=1);

/** Deliver plain-text channel messages through the bounded, operator-configured SMTP mailer. */

namespace App\Services\Notifications\Adapters;

use App\Contracts\Notifications\ChannelAdapter;
use App\Contracts\Notifications\ChannelConfiguration;
use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\ChannelMessage;
use App\Data\Notifications\EmailChannelConfiguration;
use App\Exceptions\ChannelDeliveryFailed;
use App\Mail\ChannelNotificationMail;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/** Explicit SMTP avoids the application's default log mailer recording verification codes or incident text. */
final class EmailChannelAdapter implements ChannelAdapter
{
    /**
     * @param ChannelConfiguration $configuration Validated email recipient, never a tenant-provided SMTP server.
     * @param ChannelMessage $message Subject and plain-text content.
     * @return ChannelDeliveryReceipt SMTP acceptance; inbox delivery is outside the application's control.
     * @throws ChannelDeliveryFailed On mismatched configuration or SMTP failure; no original exception escapes.
     */
    public function send(#[\SensitiveParameter] ChannelConfiguration $configuration, #[\SensitiveParameter] ChannelMessage $message): ChannelDeliveryReceipt
    {
        if (!$configuration instanceof EmailChannelConfiguration) {
            throw new ChannelDeliveryFailed('The email channel configuration is invalid.');
        }

        try {
            Mail::mailer('smtp')->to($configuration->address)->send(new ChannelNotificationMail($message));
        } catch (TransportExceptionInterface) {
            throw new ChannelDeliveryFailed('The email provider could not accept the notification.');
        }

        return new ChannelDeliveryReceipt();
    }
}
