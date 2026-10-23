<?php

declare(strict_types=1);

/** Describe one validated monitor list request: free-text search, exact filters and the requested page. */

namespace App\Data\Monitoring;

use App\Enums\Monitoring\MonitorOperationalState;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\Monitoring\MonitorType;
use InvalidArgumentException;

/** Carries user intent only; tenant identity is always supplied separately by the trusted context. */
final readonly class MonitorListFilter
{
    /** Maximum search length in Unicode characters, bounding the generated pattern size. */
    public const int QUERY_MAX_LENGTH = 100;

    /**
     * @param string $query Trimmed search text; empty means no text constraint. Wildcards are literal.
     * @param MonitorType|null $type Exact configuration variant, or null for every type.
     * @param MonitorStatus|null $status Exact latest observation, or null for every status.
     * @param MonitorOperationalState|null $state Derived scheduling eligibility, or null for every state.
     * @param int $page One-based page number; pages beyond the last one yield an empty page.
     * @throws InvalidArgumentException When the query exceeds its bound or the page is not positive.
     */
    public function __construct(
        public string $query = '',
        public ?MonitorType $type = null,
        public ?MonitorStatus $status = null,
        public ?MonitorOperationalState $state = null,
        public int $page = 1,
    ) {
        if (mb_strlen($query) > self::QUERY_MAX_LENGTH || trim($query) !== $query) {
            throw new InvalidArgumentException('Monitor search text must be trimmed and bounded.');
        }

        if ($page < 1) {
            throw new InvalidArgumentException('Monitor list pages are one-based.');
        }
    }

    /** @return bool Whether any search text or exact filter narrows the team's monitors. */
    public function isFiltered(): bool
    {
        return $this->query !== ''
            || $this->type !== null
            || $this->status !== null
            || $this->state !== null;
    }

    /**
     * @return array{q: string, type: string|null, status: string|null, state: string|null} Scalar echo of the
     *         accepted filters for the page; the search text is the user's own input, never stored data.
     */
    public function toArray(): array
    {
        return [
            'q' => $this->query,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'state' => $this->state?->value,
        ];
    }
}
