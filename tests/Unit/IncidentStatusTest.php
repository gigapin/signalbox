<?php

declare(strict_types=1);

/** Verify the complete incident status transition graph and lifecycle configuration without persistence. */

use App\Enums\Incidents\IncidentStatus;

it('admits exactly the documented transitions', function (IncidentStatus $from, IncidentStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with(function (): iterable {
    $open = [IncidentStatus::Investigating, IncidentStatus::Identified, IncidentStatus::Monitoring];

    foreach (IncidentStatus::cases() as $from) {
        foreach (IncidentStatus::cases() as $to) {
            $allowed = match (true) {
                $from === $to => false,
                in_array($from, $open, true) => true,
                default => $to === IncidentStatus::Investigating,
            };

            yield "{$from->value} to {$to->value}" => [$from, $to, $allowed];
        }
    }
});

it('treats every status except resolved as open', function (IncidentStatus $status, bool $open): void {
    expect($status->isOpen())->toBe($open);
})->with([
    'investigating' => [IncidentStatus::Investigating, true],
    'identified' => [IncidentStatus::Identified, true],
    'monitoring' => [IncidentStatus::Monitoring, true],
    'resolved' => [IncidentStatus::Resolved, false],
]);

it('declares a positive reopen window in seconds', function (): void {
    $config = require __DIR__.'/../../config/incidents.php';

    expect($config)->toBe(['reopen_window_seconds' => 900]);
});
