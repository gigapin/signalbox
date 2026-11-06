<?php

declare(strict_types=1);

/** Verify daily local silence intervals at midnight and real UTC instants around DST transitions. */

use App\Data\Notifications\QuietHours;

it('rejects invalid times empty intervals offsets and unknown timezones', function (string $start, string $end, string $zone): void {
    expect(fn () => new QuietHours($start, $end, $zone))->toThrow(InvalidArgumentException::class);
})->with([
    ['24:00', '07:00', 'UTC'],
    ['22:00', '7:00', 'UTC'],
    ['22:00:00', '07:00', 'UTC'],
    ['22:00', '07:60', 'UTC'],
    ['22:00', '22:00', 'UTC'],
    ['', '07:00', 'UTC'],
    ['22:00', '07:00', '+02:00'],
    ['22:00', '07:00', 'Mars/Olympus'],
]);

it('uses inclusive start exclusive end and seconds within a minute', function (string $at, bool $expected): void {
    expect((new QuietHours('09:30', '17:00', 'UTC'))->contains(new DateTimeImmutable($at)))->toBe($expected);
})->with([
    ['2026-10-30T09:29:59Z', false],
    ['2026-10-30T09:30:00Z', true],
    ['2026-10-30T16:59:59Z', true],
    ['2026-10-30T17:00:00Z', false],
]);

it('wraps past midnight without mutating the supplied instant', function (string $at, bool $expected): void {
    $instant = new DateTimeImmutable($at);
    $original = $instant->format(DateTimeInterface::ATOM);

    expect((new QuietHours('22:00', '07:00', 'Europe/Rome'))->contains($instant))->toBe($expected)
        ->and($instant->format(DateTimeInterface::ATOM))->toBe($original);
})->with([
    ['2026-10-30T20:59:59Z', false],
    ['2026-10-30T21:00:00Z', true],
    ['2026-10-30T23:00:00Z', true],
    ['2026-10-31T05:59:59Z', true],
    ['2026-10-31T06:00:00Z', false],
    ['2026-11-01T06:30:00+09:00', true],
]);

it('evaluates both occurrences of the repeated Rome wall clock hour', function (string $at, bool $expected): void {
    expect((new QuietHours('02:15', '02:45', 'Europe/Rome'))->contains(new DateTimeImmutable($at)))->toBe($expected);
})->with([
    ['2026-10-25T00:14:59Z', false],
    ['2026-10-25T00:15:00Z', true],
    ['2026-10-25T00:44:59Z', true],
    ['2026-10-25T00:45:00Z', false],
    ['2026-10-25T00:59:59Z', false],
    ['2026-10-25T01:00:00Z', false],
    ['2026-10-25T01:15:00Z', true],
    ['2026-10-25T01:44:59Z', true],
    ['2026-10-25T01:45:00Z', false],
]);

it('does not invent a skipped spring hour and preserves ranges spanning the jump', function (string $start, string $end, string $at, bool $expected): void {
    expect((new QuietHours($start, $end, 'Europe/Rome'))->contains(new DateTimeImmutable($at)))->toBe($expected);
})->with([
    ['02:15', '02:45', '2026-03-29T00:59:59Z', false],
    ['02:15', '02:45', '2026-03-29T01:00:00Z', false],
    ['01:30', '03:30', '2026-03-29T00:29:59Z', false],
    ['01:30', '03:30', '2026-03-29T00:30:00Z', true],
    ['01:30', '03:30', '2026-03-29T00:59:59Z', true],
    ['01:30', '03:30', '2026-03-29T01:00:00Z', true],
    ['01:30', '03:30', '2026-03-29T01:29:59Z', true],
    ['01:30', '03:30', '2026-03-29T01:30:00Z', false],
    ['02:15', '03:30', '2026-03-29T01:00:00Z', true],
]);

it('handles negative fractional and non-European DST offsets', function (string $zone, string $at, bool $expected): void {
    expect((new QuietHours('01:15', '01:45', $zone))->contains(new DateTimeImmutable($at)))->toBe($expected);
})->with([
    ['America/New_York', '2026-11-01T05:15:00Z', true],
    ['America/New_York', '2026-11-01T06:15:00Z', true],
    ['America/New_York', '2026-11-01T06:45:00Z', false],
    ['Asia/Kathmandu', '2026-10-30T19:30:00Z', true],
    ['Asia/Kathmandu', '2026-10-30T20:00:00Z', false],
]);

it('supports intervals ending or starting at midnight', function (): void {
    expect((new QuietHours('22:00', '00:00', 'UTC'))->contains(new DateTimeImmutable('2026-10-30T23:59:59Z')))->toBeTrue()
        ->and((new QuietHours('22:00', '00:00', 'UTC'))->contains(new DateTimeImmutable('2026-10-31T00:00:00Z')))->toBeFalse()
        ->and((new QuietHours('00:00', '07:00', 'UTC'))->contains(new DateTimeImmutable('2026-10-31T00:00:00Z')))->toBeTrue();
});

it('finds the first permitted real instant across midnight fractional offsets and DST', function (string $start, string $end, string $zone, string $at, string $expected): void {
    $instant = new DateTimeImmutable($at);
    $original = $instant->format('c.u');
    $rule = new QuietHours($start, $end, $zone);
    $allowed = $rule->nextAllowedAt($instant);

    expect($allowed->format('Y-m-d\TH:i:s.uP'))->toBe($expected)
        ->and($rule->contains($allowed))->toBeFalse()
        ->and($instant->format('c.u'))->toBe($original);

    if ($rule->contains($instant)) {
        expect($rule->contains($allowed->modify('-1 microsecond')))->toBeTrue();
    }
})->with([
    ['16:00', '17:00', 'Europe/Rome', '2026-11-05T15:30:00.123456Z', '2026-11-05T16:00:00.000000+00:00'],
    ['16:00', '17:00', 'Europe/Rome', '2026-11-05T16:00:00.123456Z', '2026-11-05T16:00:00.123456+00:00'],
    ['22:00', '07:00', 'Europe/Rome', '2026-11-05T22:00:00Z', '2026-11-06T06:00:00.000000+00:00'],
    ['22:00', '00:00', 'UTC', '2026-11-05T23:59:59Z', '2026-11-06T00:00:00.000000+00:00'],
    ['01:15', '01:45', 'Asia/Kathmandu', '2026-10-30T19:30:00Z', '2026-10-30T20:00:00.000000+00:00'],
    ['02:15', '02:45', 'Europe/Rome', '2026-10-25T00:15:00Z', '2026-10-25T00:45:00.000000+00:00'],
    ['02:15', '02:45', 'Europe/Rome', '2026-10-25T01:15:00Z', '2026-10-25T01:45:00.000000+00:00'],
    ['01:30', '03:30', 'Europe/Rome', '2026-10-25T00:00:00Z', '2026-10-25T02:30:00.000000+00:00'],
    ['01:30', '02:30', 'Europe/Rome', '2026-03-29T00:45:00Z', '2026-03-29T01:00:00.000000+00:00'],
    ['01:30', '03:30', 'Europe/Rome', '2026-03-29T00:45:00Z', '2026-03-29T01:30:00.000000+00:00'],
    ['01:15', '01:45', 'America/New_York', '2026-11-01T05:15:00Z', '2026-11-01T05:45:00.000000+00:00'],
    ['01:15', '01:45', 'America/New_York', '2026-11-01T06:15:00Z', '2026-11-01T06:45:00.000000+00:00'],
    ['01:30', '02:30', 'Australia/Lord_Howe', '2026-10-03T15:15:00Z', '2026-10-03T15:30:00.000000+00:00'],
]);
