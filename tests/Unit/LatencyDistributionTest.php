<?php

declare(strict_types=1);

/** Verify exact percentile positions and frequency merging without infrastructure. */

use App\Data\Metrics\LatencyDistribution;

it('returns null percentiles and zero totals without samples', function (): void {
    $empty = new LatencyDistribution();

    expect($empty->percentile(50))->toBeNull()
        ->and($empty->percentile(95))->toBeNull()
        ->and($empty->samples())->toBe(0)
        ->and($empty->sumMilliseconds())->toBe(0);
});

it('selects the sample at position ceil(p * n)', function (array $samples, int $percent, int $expected): void {
    expect(LatencyDistribution::fromSamples($samples)->percentile($percent))->toBe($expected);
})->with([
    'single value median' => [[7], 50, 7],
    'single value high' => [[7], 95, 7],
    'even median takes the lower middle' => [[10, 20, 30, 40], 50, 20],
    'odd median' => [[10, 20, 30], 50, 20],
    'twenty samples stop at the nineteenth' => [range(1, 20), 95, 19],
    'twenty-one samples round up to the twentieth' => [range(1, 21), 95, 20],
    'one hundred samples stop at the ninety-fifth' => [range(1, 100), 95, 95],
    'repeated values' => [[5, 5, 5, 9], 50, 5],
    'zero is a real value' => [[0, 0, 0, 100], 50, 0],
    'unsorted input' => [[30, 10, 20], 50, 20],
]);

it('uses exact integer positions where binary floating point would overshoot', function (): void {
    // In IEEE arithmetic 0.07 * 100 exceeds 7, which would wrongly select the eighth position.
    expect(LatencyDistribution::fromSamples(range(1, 100))->percentile(7))->toBe(7);
});

it('merges disjoint samples exactly, which differs from averaging their percentiles', function (): void {
    $busy = LatencyDistribution::fromSamples(array_fill(0, 99, 10));
    $quiet = LatencyDistribution::fromSamples([1000]);
    $merged = $busy->merge($quiet);

    expect($merged->percentile(50))->toBe(10)
        ->and($merged->percentile(95))->toBe(10)
        ->and($merged->samples())->toBe(100)
        ->and($merged->sumMilliseconds())->toBe(1990)
        ->and($merged->counts)->toBe([10 => 99, 1000 => 1]);
});

it('discards zero counts and rejects negative frequencies or invalid percentiles', function (): void {
    expect((new LatencyDistribution([5 => 0, 3 => 2]))->counts)->toBe([3 => 2])
        ->and(fn () => new LatencyDistribution([-1 => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LatencyDistribution([1 => -1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new LatencyDistribution())->percentile(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new LatencyDistribution())->percentile(101))->toThrow(InvalidArgumentException::class);
});

it('merges to the same distribution in any order and grouping', function (): void {
    $first = LatencyDistribution::fromSamples([1, 2, 2]);
    $second = LatencyDistribution::fromSamples([2, 900]);
    $third = LatencyDistribution::fromSamples([0, 1]);
    $expected = LatencyDistribution::fromSamples([1, 2, 2, 2, 900, 0, 1])->counts;

    expect($first->merge($second)->merge($third)->counts)->toBe($expected)
        ->and($third->merge($second->merge($first))->counts)->toBe($expected)
        ->and($first->merge(new LatencyDistribution())->counts)->toBe($first->counts);
});
