<?php

declare(strict_types=1);

/** Render recipient-only channel notifications without persisting or queue-serializing their content. */

namespace App\Mail;

use App\Data\Notifications\ChannelMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent synchronously by the SMTP adapter; delivery jobs supply their own retry semantics later. */
final class ChannelNotificationMail extends Mailable
{
    /** @param ChannelMessage $message Recipient-only subject and plain text; may contain a verification code. */
    public function __construct(#[\SensitiveParameter] public readonly ChannelMessage $message)
    {
    }

    /** @return Envelope Single-line subject validated at the message boundary. */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->message->subject);
    }

    /** @return Content Text-only template; labels containing markup remain literal text. */
    public function content(): Content
    {
        return new Content(text: 'mail.channel-notification', with: ['notificationText' => $this->message->text]);
    }
}
