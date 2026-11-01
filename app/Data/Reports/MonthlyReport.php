<?php

declare(strict_types=1);

/** Everything a monthly report document states, already restricted to one team. */

namespace App\Data\Reports;

use Carbon\CarbonImmutable;

final readonly class MonthlyReport
{
    /**
     * @param string $teamName Team named on the document.
     * @param ReportMonth $month Reported month and its timezone.
     * @param CarbonImmutable $generatedAt Instant of generation, which also ends a month still in progress.
     * @param list<MonthlyReportRow> $rows Monitors ordered by name.
     */
    public function __construct(
        public string $teamName,
        public ReportMonth $month,
        public CarbonImmutable $generatedAt,
        public array $rows,
    ) {
    }

    /** @return bool Whether the month is still in progress, so its values can still change. */
    public function isPartial(): bool
    {
        return $this->generatedAt->isBefore($this->month->window()->end);
    }

    /** @return string Local start of the reported period. */
    public function periodStart(): string
    {
        return $this->local($this->month->window()->start);
    }

    /** @return string Local end of the reported period: the generation instant for a month in progress. */
    public function periodEnd(): string
    {
        return $this->local($this->month->window()->end->min($this->generatedAt));
    }

    /** @return string Local generation instant. */
    public function generated(): string
    {
        return $this->local($this->generatedAt);
    }

    /**
     * @param CarbonImmutable $instant Instant to display.
     * @return string Date and time in the team's timezone, with its offset because DST can change it within a month.
     */
    private function local(CarbonImmutable $instant): string
    {
        return $instant->setTimezone($this->month->timezone)->format('Y-m-d H:i P');
    }
}
