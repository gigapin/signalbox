<?php

declare(strict_types=1);

/** Project teams, entitlements and audit events for the platform admin pages. */

namespace App\Http\Presenters\Admin;

use App\Data\Billing\Entitlement;
use App\Enums\Billing\Plan;
use App\Enums\Billing\PlanOverrideKind;
use App\Models\PlatformAuditEvent;
use App\Models\Team;
use App\Models\TeamPlanOverride;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\TeamEntitlement;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** No model is serialized; projections hold plan data, counts and identities only, never monitor configuration. */
final readonly class PlatformTeamPresenter
{
    /**
     * @param TeamEntitlement $entitlement Effective plan resolution, honouring overrides.
     * @param PlanLimits $limits Plan quotas for usage summaries.
     */
    public function __construct(private TeamEntitlement $entitlement, private PlanLimits $limits)
    {
    }

    /**
     * @param Team $team Team with owner and override loaded.
     * @param int $monitors Monitor count of the team.
     * @return array<string, mixed> List row.
     */
    public function summary(Team $team, int $monitors): array
    {
        $entitlement = $this->entitlement->resolve($team);

        return [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'owner' => ['name' => $team->owner->name, 'email' => $team->owner->email],
            'storedPlan' => $team->plan->value,
            'entitlement' => self::entitlement($entitlement),
            'monitors' => $monitors,
            'override' => self::override($team->planOverride),
        ];
    }

    /**
     * @param Team $team Team with owner and override loaded.
     * @param int $monitors Monitor count of the team.
     * @param int $members Membership count.
     * @param iterable<PlatformAuditEvent> $events Latest audit events first.
     * @return array<string, mixed> Detail projection with usage and history.
     */
    public function detail(Team $team, int $monitors, int $members, iterable $events): array
    {
        $plan = $this->entitlement->effectivePlan($team);
        $history = [];

        foreach ($events as $event) {
            $history[] = [
                'id' => $event->id,
                'action' => $event->action,
                'actor' => $event->actor?->email,
                'changes' => $event->changes,
                'reason' => $event->reason,
                'createdAt' => self::instant($event->created_at),
            ];
        }

        return [
            ...$this->summary($team, $monitors),
            'members' => $members,
            'trialStartedAt' => self::instant($team->trial_started_at),
            'trialEndsAt' => self::instant($team->trial_ends_at),
            'createdAt' => self::instant($team->created_at),
            'usage' => [
                'used' => $monitors,
                'limit' => $this->limits->monitors($plan),
                'minimumIntervalSeconds' => $this->limits->minimumIntervalSeconds($plan),
            ],
            'events' => $history,
        ];
    }

    /**
     * @param LengthAwarePaginator<int, Team> $page Directory page.
     * @param array<int, int> $monitorCounts Monitor counts keyed by team ID.
     * @return array{data: list<array<string, mixed>>, currentPage: int, lastPage: int, perPage: int, total: int, from: int|null, to: int|null}
     *         Rows and counters; the client builds links from its own search text.
     */
    public function page(LengthAwarePaginator $page, array $monitorCounts): array
    {
        $rows = [];

        foreach ($page->items() as $team) {
            $rows[] = $this->summary($team, $monitorCounts[$team->id] ?? 0);
        }

        return [
            'data' => $rows,
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /** @return array{kinds: list<string>, plans: list<string>} Override choices offered by the form. */
    public static function options(): array
    {
        return [
            'kinds' => array_map(static fn (PlanOverrideKind $kind): string => $kind->value, PlanOverrideKind::cases()),
            'plans' => [Plan::Pro->value, Plan::Business->value],
        ];
    }

    /**
     * @param Entitlement $entitlement Resolved entitlement.
     * @return array{plan: string, source: string, expiresAt: string|null} Effective plan with its origin.
     */
    private static function entitlement(Entitlement $entitlement): array
    {
        return [
            'plan' => $entitlement->plan->value,
            'source' => $entitlement->source->value,
            'expiresAt' => self::instant($entitlement->expiresAt),
        ];
    }

    /**
     * @param TeamPlanOverride|null $override Stored override, active or expired.
     * @return array{kind: string, plan: string|null, expiresAt: string|null, reason: string, updatedAt: string|null}|null Override projection.
     */
    private static function override(?TeamPlanOverride $override): ?array
    {
        if ($override === null) {
            return null;
        }

        return [
            'kind' => $override->kind->value,
            'plan' => $override->plan?->value,
            'expiresAt' => self::instant($override->expires_at),
            'reason' => $override->reason,
            'updatedAt' => self::instant($override->updated_at),
        ];
    }

    /**
     * @param CarbonInterface|null $instant Stored instant.
     * @return string|null ISO-8601 UTC string, or null.
     */
    private static function instant(?CarbonInterface $instant): ?string
    {
        return $instant?->toIso8601ZuluString();
    }
}
