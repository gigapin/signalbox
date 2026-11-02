<?php

declare(strict_types=1);

/** Carry decrypted recipient data only between attempt admission and synchronous SMTP delivery. */

namespace App\Data\StatusPages;

/** Never serialized into Redis, failed jobs or diagnostics. */
final readonly class StatusEmailAttempt
{
    /**
     * @param string $email Confirmed destination, or the mailbox awaiting double opt-in.
     * @param string $subject Fixed single-line subject.
     * @param string $text Explicit public content or generic verification text.
     * @param string $messageId Stable UUID for SMTP Message-ID across retries.
     */
    public function __construct(
        #[\SensitiveParameter] public string $email,
        #[\SensitiveParameter] public string $subject,
        #[\SensitiveParameter] public string $text,
        public string $messageId,
    ) {
    }
}
