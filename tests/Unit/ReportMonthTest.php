<?php

declare(strict_types=1);

/** Verify calendar months in a team's timezone and their conversion to UTC, without infrastructure. */

use App\Data\Reports\ReportMonth;
use Carbon\CarbonImmutable;

it('converts local month boundaries to UTC', function (string $identifier, string $timezone, string $start, string $end, int $hours): void {
    $window = ReportMonth::parse($identifier, $timezone)->window();

    expect($window->start->format('Y-m-d\TH:i\Z'))->toBe($start)
        ->and($window->end->format('Y-m-d\TH:i\Z'))->toBe($end)
        ->and((int) $window->start->diffInHours($window->end))->toBe($hours);
})->with([
    'UTC' => ['2026-04', 'UTC', '2026-04-01T00:00Z', '2026-05-01T00:00Z', 720],
    'spring DST loses an hour' => ['2026-03', 'Europe/Rome', '2026-02-28T23:00Z', '2026-03-31T22:00Z', 743],
    'autumn DST gains an hour' => ['2026-10', 'Europe/Rome', '2026-09-30T22:00Z', '2026-10-31T23:00Z', 745],
    'year change' => ['2026-12', 'America/New_York', '2026-12-01T05:00Z', '2027-01-01T05:00Z', 744],
    'fractional offset' => ['2026-01', 'Asia/Kolkata', '2025-12-31T18:30Z', '2026-01-31T18:30Z', 744],
    'leap February' => ['2028-02', 'UTC', '2028-02-01T00:00Z', '2028-03-01T00:00Z', 696],
    'common February' => ['2027-02', 'UTC', '2027-02-01T00:00Z', '2027-03-01T00:00Z', 672],
    'January does not overflow' => ['2027-01', 'UTC', '2027-01-01T00:00Z', '2027-02-01T00:00Z', 744],
]);

it('rejects a malformed month', function (string $identifier): void {
    expect(fn () => ReportMonth::parse($identifier, 'UTC'))->toThrow(InvalidArgumentException::class);
})->with(['2026-13', '2026-00', '2026-1', '26-01', '2026-01-01', "2026-01\n", '']);

it('treats a month as started at its first local instant', function (): void {
    $month = ReportMonth::parse('2026-03', 'Europe/Rome');

    expect($month->hasStarted(CarbonImmutable::parse('2026-02-28T22:59:59Z')))->toBeFalse()
        ->and($month->hasStarted(CarbonImmutable::parse('2026-02-28T23:00:00Z')))->toBeTrue();
});

it('identifies and names the month', function (): void {
    $month = ReportMonth::parse('2026-03', 'Asia/Tokyo');

    expect($month->identifier())->toBe('2026-03')
        ->and($month->label())->toBe('March 2026');
});
