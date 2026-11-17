<?php

declare(strict_types=1);

/** Preserve throwable identity until Monolog redaction, before Laravel stringifies messages. */

namespace App\Logging;

use Illuminate\Log\Logger;
use Throwable;

/** Attach direct throwable messages to context so they receive the same policy as reported exceptions. */
final class RedactingLogger extends Logger
{
    /**
     * @param mixed $level Framework log level.
     * @param mixed $message Message accepted by Laravel, including throwable objects.
     * @param array<array-key, mixed> $context Structured diagnostic fields.
     */
    protected function writeLog($level, $message, $context): void
    {
        if ($message instanceof Throwable) {
            $context['logged_exception'] = $message;
            $message = $message->getMessage();
        }

        parent::writeLog($level, $message, $context);
    }
}
