<?php

declare(strict_types=1);

/** Identify a calendar month as a team experiences it and convert it to the UTC instants the metrics use (ADR-009). */

namespace App\Data\Reports;

use App\Data\Metrics\TimeInterval;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** The window comes from local midnights, so a month containing a DST change is an hour shorter or longer than its days suggest. */
final readonly class ReportMonth
{
    /**
     * @param int $year Calendar year.
     * @param int $month Calendar month, 1 to 12.
     * @param string $timezone IANA timezone of the team.
     */
    public function __construct(
        public int $year,
        public int $month,
        public string $timezone,
    ) {
    }

    /**
     * @param string $identifier Month as YYYY-MM.
     * @param string $timezone IANA timezone of the team.
     * @return self Parsed month.
     * @throws InvalidArgumentException When the identifier is malformed.
     */
    public static function parse(string $identifier, string $timezone): self
    {
        if (preg_match('/\A([0-9]{4})-(0[1-9]|1[0-2])\z/', $identifier, $parts) !== 1) {
            throw new InvalidArgumentException('Report months use the YYYY-MM format.');
        }

        return new self((int) $parts[1], (int) $parts[2], $timezone);
    }

    /** @return string Month as YYYY-MM. */
    public function identifier(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** @return string Month name and year for people. */
    public function label(): string
    {
        return $this->localStart()->format('F Y');
    }

    /** @return TimeInterval Half-open window in UTC, from this local month's first midnight to the next month's. */
    public function window(): TimeInterval
    {
        $start = $this->localStart();

        return new TimeInterval($start->utc(), $start->addMonthNoOverflow()->utc());
    }

    /**
     * @param CarbonImmutable $now Current instant.
     * @return bool Whether any part of the month has begun.
     */
    public function hasStarted(CarbonImmutable $now): bool
    {
        return !$this->window()->start->isAfter($now);
    }

    /** @return CarbonImmutable First local midnight of the month. */
    private function localStart(): CarbonImmutable
    {
        return new CarbonImmutable($this->identifier().'-01 00:00:00', $this->timezone);
    }
}
