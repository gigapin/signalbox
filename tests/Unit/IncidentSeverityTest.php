<?php

declare(strict_types=1);

/** Verify severity derivation for every check error and the ordering used across regions. */

use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Monitoring\CheckError;

it('derives the documented severity for every check error', function (CheckError $cause, IncidentSeverity $expected): void {
    expect(IncidentSeverity::forCause($cause))->toBe($expected);
})->with(function (): iterable {
    $critical = [
        CheckError::Timeout,
        CheckError::Connection,
        CheckError::ResolutionFailed,
        CheckError::Tls,
        CheckError::DnsMissing,
        CheckError::DnsResponse,
        CheckError::HeartbeatMissed,
    ];
    $minor = [CheckError::TlsExpiring];

    foreach (CheckError::cases() as $cause) {
        $expected = IncidentSeverity::Major;

        if (in_array($cause, $critical, true)) {
            $expected = IncidentSeverity::Critical;
        }

        if (in_array($cause, $minor, true)) {
            $expected = IncidentSeverity::Minor;
        }

        yield $cause->value => [$cause, $expected];
    }
});

it('selects the most severe candidate regardless of order', function (array $candidates, IncidentSeverity $expected): void {
    expect(IncidentSeverity::max(...$candidates))->toBe($expected);
})->with([
    'single' => [[IncidentSeverity::Minor], IncidentSeverity::Minor],
    'ascending' => [[IncidentSeverity::Minor, IncidentSeverity::Major], IncidentSeverity::Major],
    'descending' => [[IncidentSeverity::Critical, IncidentSeverity::Minor], IncidentSeverity::Critical],
    'repeated' => [[IncidentSeverity::Major, IncidentSeverity::Major], IncidentSeverity::Major],
]);

it('rejects an empty candidate list', function (): void {
    expect(fn () => IncidentSeverity::max())->toThrow(InvalidArgumentException::class);
});

it('orders ranks from minor to critical', function (): void {
    expect(IncidentSeverity::Minor->rank())->toBeLessThan(IncidentSeverity::Major->rank())
        ->and(IncidentSeverity::Major->rank())->toBeLessThan(IncidentSeverity::Critical->rank());
});
