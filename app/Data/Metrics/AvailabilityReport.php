<?php

declare(strict_types=1);

/** State what can be said about a monitor's availability over a window, following the formulas of ADR-009. */

namespace App\Data\Metrics;

/**
 * Every value that cannot be determined is null, never 0 or 100. Percentages use exact integers and round down, so
 * 99.999% is never displayed as a clean 100% and full coverage is displayed only when nothing is unknown.
 */
final readonly class AvailabilityReport
{
    /** @param AvailabilityDurations|null $durations Time split of the window; null when the retained data cannot reconstruct it. */
    public function __construct(public ?AvailabilityDurations $durations)
    {
    }

    /** @return self Report for a window that the retained data cannot reconstruct. */
    public static function unavailable(): self
    {
        return new self(null);
    }

    /**
     * @param non-empty-list<self> $reports Reports of disjoint periods.
     * @return self Report of all the periods together; unavailable as soon as any of them is, with no partial answer.
     */
    public static function combined(array $reports): self
    {
        $total = new AvailabilityDurations(0, 0, 0, 0);

        foreach ($reports as $report) {
            if ($report->durations === null) {
                return self::unavailable();
            }

            $total = $total->plus($report->durations);
        }

        return new self($total);
    }

    /** @return float|null U / (U + D) as a percentage; null without observed time. */
    public function observedPercent(): ?float
    {
        if ($this->durations === null) {
            return null;
        }

        return $this->percent($this->durations->up, $this->durations->up + $this->durations->down);
    }

    /** @return float|null (U + D) / (U + D + X) as a percentage; null with a null eligible denominator. */
    public function coveragePercent(): ?float
    {
        if ($this->durations === null) {
            return null;
        }

        return $this->percent($this->durations->up + $this->durations->down, $this->durations->eligible());
    }

    /** @return float|null Uptime of the window; null unless all its eligible time is known and that time is positive. */
    public function slaPercent(): ?float
    {
        if ($this->durations === null || $this->durations->unknown > 0) {
            return null;
        }

        return $this->observedPercent();
    }

    /**
     * @param int $part Numerator in microseconds. Below 29 years, so scaling it cannot overflow.
     * @param int $whole Denominator in microseconds.
     * @return float|null Percentage rounded down to two decimals; null with a null denominator.
     */
    private function percent(int $part, int $whole): ?float
    {
        if ($whole === 0) {
            return null;
        }

        return intdiv($part * 10_000, $whole) / 100;
    }
}
