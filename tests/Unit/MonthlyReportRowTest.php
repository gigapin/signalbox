<?php

declare(strict_types=1);

/** Verify how report values are displayed, keeping undetermined and unavailable apart and never inventing 0 or 100. */

use App\Data\Metrics\AvailabilityDurations;
use App\Data\Metrics\AvailabilityReport;
use App\Data\Reports\MonthlyReportRow;

const REPORT_HOUR = 3_600_000_000;

it('marks every value unavailable when the data cannot reconstruct the month', function (): void {
    $row = new MonthlyReportRow('Site', AvailabilityReport::unavailable(), 0);

    expect([$row->sla(), $row->observed(), $row->coverage(), $row->down(), $row->unknown(), $row->excluded()])
        ->toBe(array_fill(0, 6, 'Unavailable'));
});

it('marks an SLA with unknown time as undetermined while showing what is known', function (): void {
    $row = new MonthlyReportRow('Site', new AvailabilityReport(new AvailabilityDurations(3 * REPORT_HOUR, REPORT_HOUR, REPORT_HOUR, 0)), 1);

    expect($row->sla())->toBe('n/a')
        ->and($row->observed())->toBe('75.00%')
        ->and($row->coverage())->toBe('80.00%')
        ->and($row->down())->toBe('1h 0m 0s')
        ->and($row->unknown())->toBe('1h 0m 0s');
});

it('leaves ratios undetermined when all the time is excluded, never 100 percent', function (): void {
    $row = new MonthlyReportRow('Site', new AvailabilityReport(new AvailabilityDurations(0, 0, 0, REPORT_HOUR)), 0);

    expect([$row->sla(), $row->observed(), $row->coverage()])->toBe(['n/a', 'n/a', 'n/a'])
        ->and($row->excluded())->toBe('1h 0m 0s');
});

it('does not round an almost perfect month up to 100 percent', function (): void {
    $up = 1000 * REPORT_HOUR;
    $row = new MonthlyReportRow('Site', new AvailabilityReport(new AvailabilityDurations($up, 1_000_000, 0, 0)), 0);

    expect($row->sla())->toBe('99.99%');
});

it('leaves out leading zero units of a duration', function (int $seconds, string $expected): void {
    $row = new MonthlyReportRow('Site', new AvailabilityReport(new AvailabilityDurations(0, $seconds * 1_000_000, 0, 0)), 0);

    expect($row->down())->toBe($expected);
})->with([
    [0, '0s'],
    [59, '59s'],
    [61, '1m 1s'],
    [3661, '1h 1m 1s'],
    [90_000, '25h 0m 0s'],
]);
