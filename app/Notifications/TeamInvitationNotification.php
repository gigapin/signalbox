<?php

/** Deliver a one-time invitation link without queueing or persisting its raw token. */

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Send the link synchronously after the issuing transaction commits. */
final class TeamInvitationNotification extends Notification
{
    /**
     * @param string $teamName Team presentation name included only in the intended recipient's email.
     * @param string $url Bearer invitation URL held transiently for delivery; never logged by application code.
     */
    public function __construct(private readonly string $teamName, private readonly string $url)
    {
    }

    /**
     * @param object $notifiable Intended email recipient.
     * @return list<string> Only the mail channel is supported.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param object $notifiable Intended recipient.
     * @return MailMessage English instructions with explicit verification and expiry.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Invitation to '.$this->teamName)
            ->line('You have been invited to join '.$this->teamName.'.')
            ->line('Sign in or create an account, verify this email address, then explicitly accept the invitation. This link expires in seven days.')
            ->action('Review invitation', $this->url);
    }
}
