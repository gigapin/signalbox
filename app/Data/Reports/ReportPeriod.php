<?php

declare(strict_types=1);

/** Identify a date range as a team experiences it and convert it to the UTC instants the metrics use (ADR-009). */

namespace App\Data\Reports;

use App\Data\Metrics\TimeInterval;
use Carbon\CarbonImmutable;

/** The window comes from local midnights, so a period containing a DST change is an hour shorter or longer than its days suggest. */
final readonly class ReportPeriod
{
    /**
     * @param string $from Inclusive start date, YYYY-MM-DD.
     * @param string $to Inclusive end date, YYYY-MM-DD.
     * @param string $timezone IANA timezone of the team.
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $timezone,
    ) {
    }

    /** @return string Stable date range identifier. */
    public function identifier(): string
    {
        return $this->from.'-'.$this->to;
    }

    /** @return string Inclusive dates for people. */
    public function label(): string
    {
        return $this->from.' to '.$this->to;
    }

    /** @return TimeInterval Half-open window in UTC, from the first local midnight to midnight after the final date. */
    public function window(): TimeInterval
    {
        $start = $this->localStart();

        return new TimeInterval($start->utc(), (new CarbonImmutable($this->to.' 00:00:00', $this->timezone))->addDay()->utc());
    }

    /**
     * @param CarbonImmutable $now Current instant.
     * @return bool Whether any part of the period has begun.
     */
    public function hasStarted(CarbonImmutable $now): bool
    {
        return !$this->window()->start->isAfter($now);
    }

    /** @return CarbonImmutable First local midnight of the period. */
    private function localStart(): CarbonImmutable
    {
        return new CarbonImmutable($this->from.' 00:00:00', $this->timezone);
    }
}
