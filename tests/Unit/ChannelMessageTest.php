<?php

declare(strict_types=1);

/** Verify recipient envelope boundaries and diagnostic redaction without application services or networking. */

use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Data\Notifications\ChannelMessage;
use App\Enums\Notifications\DeliveryStatus;
use Carbon\CarbonImmutable;

it('selects a versioned UTC envelope without including message content in debug output', function (): void {
    $message = new ChannelMessage(
        '12345678-1234-4234-8234-123456789012',
        'channel.verification',
        CarbonImmutable::parse('2026-10-29 10:00:00', 'Europe/Rome'),
        'Verify channel',
        'Code 654321',
        ['code' => '654321', 'expires_in_seconds' => 900],
    );

    expect($message->payload())->toBe([
        'schema_version' => 1,
        'id' => '12345678-1234-4234-8234-123456789012',
        'event' => 'channel.verification',
        'occurred_at' => '2026-10-29T09:00:00+00:00',
        'subject' => 'Verify channel',
        'text' => 'Code 654321',
        'data' => ['code' => '654321', 'expires_in_seconds' => 900],
    ])
        ->and(print_r($message, true))->not->toContain('654321', 'Verify channel');
});

it('refuses malformed identities and content that cannot fit provider limits', function (string $id, string $event, string $subject, string $text): void {
    expect(fn () => new ChannelMessage($id, $event, CarbonImmutable::now(), $subject, $text))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'identity' => ['not-a-uuid', 'incident.opened', 'Incident opened', 'Monitor unavailable'],
    'event' => ['12345678-1234-4234-8234-123456789012', 'arbitrary.secret', 'Incident opened', 'Monitor unavailable'],
    'empty subject' => ['12345678-1234-4234-8234-123456789012', 'incident.opened', ' ', 'Monitor unavailable'],
    'subject boundary' => ['12345678-1234-4234-8234-123456789012', 'incident.opened', str_repeat('x', 201), 'Monitor unavailable'],
    'header injection' => ['12345678-1234-4234-8234-123456789012', 'incident.opened', "Incident\r\nBcc: other@example.test", 'Monitor unavailable'],
    'empty text' => ['12345678-1234-4234-8234-123456789012', 'incident.opened', 'Incident opened', ' '],
    'text boundary' => ['12345678-1234-4234-8234-123456789012', 'incident.opened', 'Incident opened', str_repeat('é', 2801)],
    'invalid UTF-8' => ['12345678-1234-4234-8234-123456789012', 'incident.opened', 'Incident opened', "\xff"],
]);

it('accepts the exact Unicode content limit and distinguishes SMS suppression from provider acceptance', function (): void {
    $message = new ChannelMessage('12345678-1234-4234-8234-123456789012', 'incident.resolved', CarbonImmutable::now(), str_repeat('s', 200), str_repeat('é', 2800));

    expect(mb_strlen($message->text))->toBe(2800)
        ->and((new ChannelDeliveryReceipt())->status())->toBe(DeliveryStatus::Sent)
        ->and((new ChannelDeliveryReceipt())->summary())->toBe('Accepted by the provider.')
        ->and((new ChannelDeliveryReceipt(stubbed: true))->status())->toBe(DeliveryStatus::Suppressed);
});
