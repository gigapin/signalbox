<?php

declare(strict_types=1);

/** Inform a mailbox owner about a registration attempt without requester-controlled content. */

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** This informational email grants no access, changes no credentials and contains no verification capability. */
final class ExistingRegistrationNotice extends Notification
{
    /**
     * @param object $notifiable Existing account owner.
     * @return list<string> Mail only, sent after commit.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param object $notifiable Existing owner; names and submitted registration fields are omitted.
     * @return MailMessage Static guidance pointing only at the configured application login address.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Registration request for your account')
            ->line('A registration request was made using an email address already linked to a Signalbox account.')
            ->line('If this was you, sign in with your existing credentials or request a password reset from the sign-in page.')
            ->action('Sign in', url('/login'))
            ->line('If you did not make this request, you can ignore this message. Your account has not been changed.');
    }
}
