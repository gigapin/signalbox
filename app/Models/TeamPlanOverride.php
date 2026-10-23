<?php

declare(strict_types=1);

/** Store the single operator-granted entitlement override of a team. */

namespace App\Models;

use App\Enums\Billing\Plan;
use App\Enums\Billing\PlanOverrideKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform data, not tenant data: it carries no TeamScope and is written only by ManagePlanOverrides under
 * the Team lock. Tenant code reads it exclusively through TeamEntitlement with an explicit team identity.
 *
 * @property int $id Override identity.
 * @property int $team_id Team receiving the override; unique.
 * @property PlanOverrideKind $kind Unlimited testing or granted plan.
 * @property Plan|null $plan Granted plan for comped overrides (Pro or Business); null for unlimited.
 * @property CarbonImmutable|null $expires_at Exclusive end; null lasts until cleared.
 * @property string $reason Operator justification.
 * @property int|null $granted_by Operator who last set it; null after that user is deleted.
 * @property CarbonImmutable|null $created_at First grant instant.
 * @property CarbonImmutable|null $updated_at Last change instant.
 */
final class TeamPlanOverride extends Model
{
    /** @var list<string> $guarded Every field requires explicit trusted assignment. */
    protected $guarded = ['*'];

    /**
     * @param CarbonImmutable $at Evaluation instant.
     * @return bool Whether the override still applies; the expiry is exclusive like the trial end.
     */
    public function isActiveAt(CarbonImmutable $at): bool
    {
        return $this->expires_at === null || $at->lessThan($this->expires_at);
    }

    /** @return BelongsTo<Team, $this> Team carrying the override. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return array<string, string> Typed kind and plan with immutable UTC timestamps. */
    protected function casts(): array
    {
        return [
            'kind' => PlanOverrideKind::class,
            'plan' => Plan::class,
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
