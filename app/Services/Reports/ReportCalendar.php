<?php

declare(strict_types=1);

/** Resolve complete selectable dates from the team's authoritative entitlement. */

namespace App\Services\Reports;

use App\Models\Team;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use Carbon\CarbonImmutable;

/** Shared bounds used by the page and rechecked by downloads. */
final readonly class ReportCalendar
{
    /** @param TeamEntitlement $entitlement Effective plan resolver.
     * @param PlanLimits $limits Central retention matrix.
     */
    public function __construct(private TeamEntitlement $entitlement, private PlanLimits $limits)
    {
    }

    /** @param Team $team Authorized team.
     * @return array{min: string, max: string, from: string, to: string} Inclusive dates in team timezone.
     */
    public function forTeam(Team $team): array
    {
        $now = CarbonImmutable::now('UTC');
        $days = $this->limits->rollupRetentionDays($this->entitlement->effectivePlan($team));
        $cutoff = $now->subDays($days)->setTimezone($team->timezone);
        $min = $cutoff->startOfDay();
        if ($min->isBefore($cutoff)) {
            $min = $min->addDay();
        }
        $local = $now->setTimezone($team->timezone);
        return [
            'min' => $min->toDateString(),
            'max' => $local->subDay()->toDateString(),
            'from' => $local->startOfMonth()->subMonth()->toDateString(),
            'to' => $local->startOfMonth()->subDay()->toDateString(),
        ];
    }
}
