<?php

declare(strict_types=1);

/** Verify half-open interval clipping and merging used for excluded SLA time. */

use App\Data\Metrics\TimeInterval;
use Carbon\CarbonImmutable;

/**
 * @param int $start Start minute after a fixed UTC origin.
 * @param int $end End minute after the same origin.
 * @return TimeInterval Interval [start, end).
 */
function minutes(int $start, int $end): TimeInterval
{
    $origin = CarbonImmutable::parse('2026-10-28 00:00:00', 'UTC');

    return new TimeInterval($origin->addMinutes($start), $origin->addMinutes($end));
}

/**
 * @param list<TimeInterval> $intervals Intervals to describe.
 * @return list<array{int, int}> Start and end minutes after the origin.
 */
function describeMinutes(array $intervals): array
{
    $origin = CarbonImmutable::parse('2026-10-28 00:00:00', 'UTC');

    return array_map(static fn (TimeInterval $interval): array => [
        (int) $origin->diffInMinutes($interval->start),
        (int) $origin->diffInMinutes($interval->end),
    ], $intervals);
}

it('merges overlapping, adjacent and contained intervals in any order', function (array $input, array $expected): void {
    expect(describeMinutes(TimeInterval::union($input)))->toBe($expected);
})->with([
    'empty' => [[], []],
    'disjoint' => [[minutes(30, 40), minutes(0, 10)], [[0, 10], [30, 40]]],
    'adjacent' => [[minutes(0, 10), minutes(10, 20)], [[0, 20]]],
    'overlapping' => [[minutes(5, 15), minutes(0, 10)], [[0, 15]]],
    'contained' => [[minutes(0, 60), minutes(10, 20), minutes(30, 40)], [[0, 60]]],
    'chain' => [[minutes(0, 10), minutes(20, 30), minutes(9, 21)], [[0, 30]]],
]);

it('clips to bounds and reports no overlap as null', function (): void {
    $bounds = minutes(10, 20);

    expect(describeMinutes([minutes(0, 15)->clip($bounds)]))->toBe([[10, 15]])
        ->and(describeMinutes([minutes(5, 30)->clip($bounds)]))->toBe([[10, 20]])
        ->and(minutes(20, 30)->clip($bounds))->toBeNull()
        ->and(minutes(0, 10)->clip($bounds))->toBeNull()
        ->and(minutes(0, 90)->seconds())->toBe(5400);
});

it('rejects empty and reversed intervals and normalizes to UTC', function (): void {
    $instant = CarbonImmutable::parse('2026-10-28 12:00:00', 'Europe/Rome');

    expect(fn () => new TimeInterval($instant, $instant))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TimeInterval($instant, $instant->subSecond()))->toThrow(InvalidArgumentException::class)
        ->and((new TimeInterval($instant, $instant->addHour()))->start->toIso8601String())->toBe('2026-10-28T11:00:00+00:00');
});
