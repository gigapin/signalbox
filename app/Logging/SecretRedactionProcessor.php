<?php

declare(strict_types=1);

/** Redact log records before any handler formats or ships them. */

namespace App\Logging;

use App\Services\Security\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Message and context strings lose URL query values; sensitive context keys lose their values.
 * Exception objects are kept as-is: code that logs transport failures must redact messages first.
 */
final readonly class SecretRedactionProcessor implements ProcessorInterface
{
    /** @param SecretRedactor $redactor Shared redaction rules. */
    public function __construct(private SecretRedactor $redactor)
    {
    }

    /**
     * @param LogRecord $record Original immutable record.
     * @return LogRecord Copy with redacted message, context and extra data.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->message($record->message),
            context: $this->redactor->context($record->context),
            extra: $this->redactor->context($record->extra),
        );
    }
}
