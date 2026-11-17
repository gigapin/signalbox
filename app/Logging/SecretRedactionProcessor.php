<?php

declare(strict_types=1);

/** Redact log records before any handler formats or ships them. */

namespace App\Logging;

use App\Services\Security\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use PDOException;
use Throwable;

/** Replace throwable objects with safe diagnostics before formatters can serialize messages or arguments. */
final readonly class SecretRedactionProcessor implements ProcessorInterface
{
    /** @param SecretRedactor $redactor Shared redaction rules. */
    public function __construct(private SecretRedactor $redactor)
    {
    }

    /**
     * @param LogRecord $record Original immutable record.
     * @return LogRecord Sanitized copy; database failures suppress free text throughout the record.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $database = $this->containsDatabaseException([$record->context, $record->extra]);

        return $record->with(
            message: $database ? 'Database operation failed.' : $this->redactor->message($record->message),
            context: $this->redactor->context($this->sanitize($record->context, $database)),
            extra: $this->redactor->context($this->sanitize($record->extra, $database)),
        );
    }

    /**
     * @param array<array-key, mixed> $values Nested record fields or exception chains.
     * @return bool Whether any throwable is or wraps a PDO failure, including Laravel QueryException.
     */
    private function containsDatabaseException(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value) && $this->containsDatabaseException($value)) {
                return true;
            }

            if ($value instanceof Throwable) {
                do {
                    if ($value instanceof PDOException) {
                        return true;
                    }

                    $value = $value->getPrevious();
                } while ($value !== null);
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $values Nested record fields.
     * @param bool $database Suppress all free text when it could duplicate SQL or driver detail.
     * @return array<array-key, mixed> Scalar diagnostics without serializable exception objects or trace arguments.
     */
    private function sanitize(array $values, bool $database): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            if ($database && is_string($key) && preg_match('/sql|binding|email|hash|ciphertext/i', $key) === 1) {
                $safe[$key] = SecretRedactor::PLACEHOLDER;
            } elseif ($value instanceof Throwable) {
                $safe[$key] = $this->exception($value, $database);
            } elseif (is_array($value)) {
                $safe[$key] = $this->sanitize($value, $database);
            } elseif ($database && (is_string($value) || is_object($value))) {
                $safe[$key] = SecretRedactor::PLACEHOLDER;
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @param Throwable $exception Original exception, never mutated or passed to a formatter.
     * @param bool $database Whether the record contains a database failure.
     * @return array<string, mixed> Class, origin and safe code, with an argument-free chain; no database messages.
     */
    private function exception(Throwable $exception, bool $database): array
    {
        $code = (string) $exception->getCode();
        $safe = [
            'class' => $exception::class,
            'code' => preg_match('/\A[A-Z0-9]{1,10}\z/', $code) === 1 ? $code : SecretRedactor::PLACEHOLDER,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'message' => $database ? 'Database operation failed.' : $this->redactor->message($exception->getMessage()),
        ];

        if ($exception->getPrevious() !== null) {
            $safe['previous'] = $this->exception($exception->getPrevious(), $database);
        }

        return $safe;
    }
}
