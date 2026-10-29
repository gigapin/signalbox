<?php

declare(strict_types=1);

/** Carry transport-independent notification content and a stable identity chosen by the caller. */

namespace App\Data\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** No Eloquent model, secret destination or provider credential belongs in this recipient-only value. */
final readonly class ChannelMessage
{
    /**
     * @param string $id UUID retained by the caller across attempts for receiver deduplication.
     * @param string $event channel.verification or an incident lifecycle event.
     * @param CarbonImmutable $occurredAt Original event time, independent of delivery attempts.
     * @param string $subject Single-line subject, at most 200 characters.
     * @param string $text Plain message, at most 2800 characters to fit Slack and Telegram without truncation.
     * @param array<string, string|int|bool|null> $data Minimal event fields explicitly selected by the caller.
     * @throws InvalidArgumentException When identity, event or content cannot be delivered safely.
     */
    public function __construct(
        public string $id,
        public string $event,
        public CarbonImmutable $occurredAt,
        #[\SensitiveParameter] public string $subject,
        #[\SensitiveParameter] public string $text,
        #[\SensitiveParameter] public array $data = [],
    ) {
        if (!Str::isUuid($id)
            || !in_array($event, ['channel.verification', 'incident.opened', 'incident.reopened', 'incident.escalated', 'incident.resolved'], true)
            || !mb_check_encoding($subject, 'UTF-8')
            || !mb_check_encoding($text, 'UTF-8')
            || trim($subject) === ''
            || mb_strlen($subject) > 200
            || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1
            || trim($text) === ''
            || mb_strlen($text) > 2800) {
            throw new InvalidArgumentException('The notification message is invalid or exceeds its content limits.');
        }
    }

    /** @return array{schema_version: int, id: string, event: string, occurred_at: string, subject: string, text: string, data: array<string, string|int|bool|null>} Explicit webhook envelope; never serialize a model. */
    public function payload(): array
    {
        return [
            'schema_version' => 1,
            'id' => $this->id,
            'event' => $this->event,
            'occurred_at' => $this->occurredAt->utc()->toIso8601String(),
            'subject' => $this->subject,
            'text' => $this->text,
            'data' => $this->data,
        ];
    }

    /** @return array{id: string, event: string} Diagnostic identity without recipient content or verification codes. */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'event' => $this->event];
    }
}
