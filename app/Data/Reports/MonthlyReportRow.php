<?php

declare(strict_types=1);

/** One monitor's line in a monthly report, with its values as people read them. */

namespace App\Data\Reports;

use App\Data\Metrics\AvailabilityReport;

/**
 * Keeps two absences apart: "Unavailable" means the retained data cannot reconstruct the month, while "n/a" means the data
 * exists and the value is undetermined, such as an SLA with unknown time. Neither is ever displayed as 0 or 100.
 */
final readonly class MonthlyReportRow
{
    /**
     * @param string $monitorName Name only: monitor configuration may contain secrets and never reaches a report.
     * @param AvailabilityReport $availability Availability over the report window.
     * @param int $incidents Incidents that started inside the report window.
     */
    public function __construct(
        public string $monitorName,
        public AvailabilityReport $availability,
        public int $incidents,
    ) {
    }

    /** @return string SLA of the month. */
    public function sla(): string
    {
        return $this->percent($this->availability->slaPercent());
    }

    /** @return string Uptime over the observed time. */
    public function observed(): string
    {
        return $this->percent($this->availability->observedPercent());
    }

    /** @return string Share of the eligible time with a known state. */
    public function coverage(): string
    {
        return $this->percent($this->availability->coveragePercent());
    }

    /** @return string Time spent down. */
    public function down(): string
    {
        return $this->duration($this->availability->durations?->down);
    }

    /** @return string Eligible time without a known state. */
    public function unknown(): string
    {
        return $this->duration($this->availability->durations?->unknown);
    }

    /** @return string Time inside pauses, plan suspensions and maintenance windows. */
    public function excluded(): string
    {
        return $this->duration($this->availability->durations?->excluded);
    }

    /**
     * @param float|null $value Percentage already rounded down to two decimals.
     * @return string Displayed value.
     */
    private function percent(?float $value): string
    {
        if ($this->availability->durations === null) {
            return 'Unavailable';
        }

        return $value === null ? 'n/a' : number_format($value, 2).'%';
    }

    /**
     * @param int|null $microseconds Exact duration; null when the window cannot be reconstructed.
     * @return string Hours, minutes and seconds, leaving out leading zero units.
     */
    private function duration(?int $microseconds): string
    {
        if ($microseconds === null) {
            return 'Unavailable';
        }

        $seconds = intdiv($microseconds, 1_000_000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m {$rest}s";
        }

        return $minutes > 0 ? "{$minutes}m {$rest}s" : "{$rest}s";
    }
}
