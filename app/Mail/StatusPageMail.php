<?php

declare(strict_types=1);

/** Render verification and public update emails without queue-serializing any recipient data. */

namespace App\Mail;

use App\Data\StatusPages\StatusEmailAttempt;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** The durable worker sends this synchronously through the bounded SMTP mailer. */
final class StatusPageMail extends Mailable
{
    /** @param StatusEmailAttempt $attempt Transient recipient-only content and stable message identity. */
    public function __construct(#[\SensitiveParameter] public readonly StatusEmailAttempt $attempt)
    {
    }

    /** @return Envelope Fixed single-line subject, independent of user-submitted labels. */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->attempt->subject);
    }

    /** @return Content Plain text; published markup remains literal text. */
    public function content(): Content
    {
        return new Content(text: 'mail.channel-notification', with: ['notificationText' => $this->attempt->text]);
    }

    /** @return Headers Stable Message-ID helps recipients recognize a repeated uncertain SMTP acceptance. */
    public function headers(): Headers
    {
        return new Headers(messageId: $this->attempt->messageId.'@status.signalbox.invalid');
    }
}
