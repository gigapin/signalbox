<?php

declare(strict_types=1);

/** Describe one validated incident list request: lifecycle state, severity and the requested page. */

namespace App\Data\Incidents;

use App\Enums\Incidents\IncidentListState;
use App\Enums\Incidents\IncidentSeverity;
use InvalidArgumentException;

/** Carries user intent only; tenant identity is always supplied separately by the trusted context. */
final readonly class IncidentListFilter
{
    /**
     * @param IncidentListState|null $state Open or resolved incidents, or null for both.
     * @param IncidentSeverity|null $severity Exact current severity, or null for every severity.
     * @param int $page One-based page number; pages beyond the last one yield an empty page.
     * @throws InvalidArgumentException When the page is not positive.
     */
    public function __construct(
        public ?IncidentListState $state = null,
        public ?IncidentSeverity $severity = null,
        public int $page = 1,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Incident list pages are one-based.');
        }
    }

    /** @return bool Whether any filter narrows the team's incidents. */
    public function isFiltered(): bool
    {
        return $this->state !== null || $this->severity !== null;
    }

    /** @return array{state: string|null, severity: string|null} Scalar echo of the accepted filters. */
    public function toArray(): array
    {
        return [
            'state' => $this->state?->value,
            'severity' => $this->severity?->value,
        ];
    }
}
