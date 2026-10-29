<?php

declare(strict_types=1);

/** Verify the single plan definition and refusal of incomplete entitlement configuration. */

use App\Enums\Billing\Plan;
use App\Services\Billing\PlanLimits;
use Illuminate\Config\Repository;

it('reads the ADR-010 monitor quotas and minimum intervals', function (Plan $plan, int $monitors, int $interval): void {
    $limits = new PlanLimits(new Repository(['plans' => require __DIR__.'/../../config/plans.php']));

    expect($limits->monitors($plan))->toBe($monitors)
        ->and($limits->minimumIntervalSeconds($plan))->toBe($interval);
})->with([
    'free' => [Plan::Free, 5, 300],
    'pro' => [Plan::Pro, 50, 60],
    'business' => [Plan::Business, 200, 30],
    // Operator testing profile: high but finite, with the validator's 30-second floor.
    'internal' => [Plan::Internal, 1000, 30],
]);

it('declares every adopted entitlement for every plan', function (): void {
    $plans = require __DIR__.'/../../config/plans.php';

    expect(array_keys($plans))->toBe(array_map(fn (Plan $plan): string => $plan->value, Plan::cases()));

    foreach ($plans as $entitlements) {
        expect(array_keys($entitlements))->toBe(['monitors', 'min_interval_seconds', 'status_pages', 'rollup_retention_days']);
    }
});

it('never treats a missing or invalid entitlement as unlimited', function (mixed $value): void {
    $limits = new PlanLimits(new Repository(['plans' => ['free' => ['monitors' => $value, 'min_interval_seconds' => $value]]]));

    expect(fn () => $limits->monitors(Plan::Free))->toThrow(LogicException::class)
        ->and(fn () => $limits->minimumIntervalSeconds(Plan::Free))->toThrow(LogicException::class)
        ->and(fn () => $limits->monitors(Plan::Pro))->toThrow(LogicException::class);
})->with([null, 0, -1, '5', 5.0]);
