<?php

declare(strict_types=1);

/** Verify the ADR-009 formulas for uptime, coverage and SLA, including every case that must stay null. */

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\AvailabilityReport;

it('calculates uptime, coverage and SLA from durations', function (array $durations, ?float $observed, ?float $coverage, ?float $sla): void {
    $report = new AvailabilityReport(new AvailabilityDurations(...$durations));

    expect($report->observedPercent())->toBe($observed)
        ->and($report->coveragePercent())->toBe($coverage)
        ->and($report->slaPercent())->toBe($sla);
})->with([
    'fully known, all up' => [[100, 0, 0, 0], 100.0, 100.0, 100.0],
    'fully known, partly down' => [[75, 25, 0, 0], 75.0, 100.0, 75.0],
    'fully known, all down is a real zero' => [[0, 100, 0, 0], 0.0, 100.0, 0.0],
    'unknown time removes only the SLA' => [[60, 20, 20, 0], 75.0, 80.0, null],
    'exclusions leave the percentages unchanged' => [[75, 25, 0, 1000], 75.0, 100.0, 75.0],
    'no observed time at all' => [[0, 0, 100, 0], null, 0.0, null],
    'everything excluded has a null denominator' => [[0, 0, 0, 100], null, null, null],
    'no time at all has a null denominator' => [[0, 0, 0, 0], null, null, null],
]);

it('rounds down so an imperfect window never displays as 100 percent', function (): void {
    $report = new AvailabilityReport(new AvailabilityDurations(999_999, 1, 0, 0));
    $partial = new AvailabilityReport(new AvailabilityDurations(999_999, 0, 1, 0));

    expect($report->observedPercent())->toBe(99.99)
        ->and($report->slaPercent())->toBe(99.99)
        ->and($partial->coveragePercent())->toBe(99.99)
        ->and($partial->slaPercent())->toBeNull();
});

it('stays exact over the longest retained window, where the percentage would overflow without integer care', function (): void {
    $twoYears = 730 * 86_400 * 1_000_000;

    expect((new AvailabilityReport(new AvailabilityDurations($twoYears - 1, 1, 0, 0)))->slaPercent())->toBe(99.99)
        ->and((new AvailabilityReport(new AvailabilityDurations($twoYears, 0, 0, 0)))->slaPercent())->toBe(100.0);
});

it('reports every value as null when the data cannot be reconstructed', function (): void {
    $report = AvailabilityReport::unavailable();

    expect($report->durations)->toBeNull()
        ->and($report->observedPercent())->toBeNull()
        ->and($report->coveragePercent())->toBeNull()
        ->and($report->slaPercent())->toBeNull();
});

it('combines disjoint periods by their durations, not by averaging percentages', function (): void {
    $combined = AvailabilityReport::combined([
        new AvailabilityReport(new AvailabilityDurations(900, 100, 0, 0)),
        new AvailabilityReport(new AvailabilityDurations(0, 1000, 0, 500)),
    ]);

    expect($combined->observedPercent())->toBe(45.0)
        ->and($combined->durations?->excluded)->toBe(500)
        ->and(AvailabilityReport::combined([
            new AvailabilityReport(new AvailabilityDurations(1, 0, 0, 0)),
            AvailabilityReport::unavailable(),
        ])->durations)->toBeNull();
});
