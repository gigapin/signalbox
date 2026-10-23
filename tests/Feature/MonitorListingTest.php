<?php

declare(strict_types=1);

/** Verify monitor list rules without a database (state precedence, filter invariants, LIKE escaping, quota hints); the model needs the application container. */

use App\Data\Monitoring\MonitorListFilter;
use App\Data\Monitoring\MonitorUsage;
use App\Enums\Monitoring\MonitorOperationalState;
use App\Enums\Monitoring\MonitorType;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorListing;

it('derives the operational state with plan suspension taking precedence', function (bool $paused, bool $suspended, MonitorOperationalState $expected): void {
    $monitor = (new Monitor())->forceFill(['is_paused' => $paused, 'is_plan_suspended' => $suspended]);

    expect(MonitorOperationalState::of($monitor))->toBe($expected);
})->with([
    'active' => [false, false, MonitorOperationalState::Active],
    'paused' => [true, false, MonitorOperationalState::Paused],
    'suspended' => [false, true, MonitorOperationalState::Suspended],
    'paused and suspended' => [true, true, MonitorOperationalState::Suspended],
]);

it('accepts bounded trimmed filters and reports whether they narrow the list', function (): void {
    $empty = new MonitorListFilter();
    $typed = new MonitorListFilter(type: MonitorType::Dns);
    $searched = new MonitorListFilter(query: str_repeat('é', MonitorListFilter::QUERY_MAX_LENGTH));

    expect($empty->isFiltered())->toBeFalse()
        ->and($empty->toArray())->toBe(['q' => '', 'type' => null, 'status' => null, 'state' => null])
        ->and($typed->isFiltered())->toBeTrue()
        ->and($typed->toArray()['type'])->toBe('dns')
        ->and($searched->isFiltered())->toBeTrue();
});

it('rejects untrimmed or oversized search text and non-positive pages', function (string $query, int $page): void {
    expect(fn () => new MonitorListFilter(query: $query, page: $page))->toThrow(InvalidArgumentException::class);
})->with([
    'leading space' => [' api', 1],
    'too long' => [str_repeat('a', MonitorListFilter::QUERY_MAX_LENGTH + 1), 1],
    'page zero' => ['', 0],
]);

it('escapes every LIKE metacharacter, backslash first', function (string $text, string $escaped): void {
    expect(MonitorListing::likeLiteral($text))->toBe($escaped);
})->with([
    'plain' => ['checkout', 'checkout'],
    'percent' => ['100%', '100\\%'],
    'underscore' => ['api_v1', 'api\\_v1'],
    'backslash' => ['a\\b', 'a\\\\b'],
    'escaped percent stays literal' => ['\\%', '\\\\\\%'],
]);

it('reports a full quota exactly at the plan limit', function (int $used, bool $full): void {
    $usage = new MonitorUsage(used: $used, limit: 5, minimumIntervalSeconds: 300);

    expect($usage->isFull())->toBe($full)
        ->and($usage->toArray())->toBe(['used' => $used, 'limit' => 5, 'minimumIntervalSeconds' => 300, 'full' => $full]);
})->with([
    'below' => [4, false],
    'at limit' => [5, true],
    'above after downgrade' => [7, true],
]);
