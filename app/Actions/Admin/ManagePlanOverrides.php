<?php

declare(strict_types=1);

/** Grant, change and clear operator entitlement overrides under the Team quota lock. */

namespace App\Actions\Admin;

use App\Enums\Billing\Plan;
use App\Enums\Billing\PlanOverrideKind;
use App\Models\PlatformAuditEvent;
use App\Models\Team;
use App\Models\TeamPlanOverride;
use App\Models\User;
use App\Services\Admin\PlatformAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Team row lock is the one monitor quota mutations take (ADR-004), so a creation never counts against
 * an override that is changing. The operator gate is re-run inside the transaction, and the override row
 * and its audit event commit together. Re-applying identical values writes nothing.
 */
final readonly class ManagePlanOverrides
{
    /** @var int REASON_MAX_LENGTH Upper bound for operator justifications, in characters. */
    public const int REASON_MAX_LENGTH = 500;

    /**
     * @param User $actor Operator performing the change.
     * @param int $teamId Team receiving the override.
     * @param PlanOverrideKind $kind Unlimited testing or granted plan.
     * @param Plan|null $plan Pro or Business for comped overrides; must be null for unlimited.
     * @param CarbonImmutable|null $expiresAt Exclusive future end; null until cleared.
     * @param string $reason Non-empty justification.
     * @return TeamPlanOverride Current override.
     * @throws AuthorizationException When the actor is no longer an operator.
     * @throws ValidationException When the combination, expiry or reason is invalid.
     */
    public function grant(User $actor, int $teamId, PlanOverrideKind $kind, ?Plan $plan, ?CarbonImmutable $expiresAt, string $reason): TeamPlanOverride
    {
        $reason = $this->reason($reason);
        $this->assertCoherent($kind, $plan, $expiresAt);

        return $this->locked($actor, $teamId, function (Team $team) use ($actor, $kind, $plan, $expiresAt, $reason): TeamPlanOverride {
            $override = TeamPlanOverride::query()->where('team_id', $team->id)->first() ?? new TeamPlanOverride();
            $before = $override->exists ? self::snapshot($override) : null;

            $override->forceFill([
                'team_id' => $team->id,
                'kind' => $kind,
                'plan' => $plan,
                'expires_at' => $expiresAt,
            ]);

            if ($before !== null && self::snapshot($override) === $before) {
                return $override;
            }

            $override->forceFill(['reason' => $reason, 'granted_by' => $actor->id])->save();

            PlatformAuditEvent::record($actor, 'team.plan_override.granted', $team, ['before' => $before, 'after' => self::snapshot($override)], $reason);

            return $override;
        });
    }

    /**
     * @param User $actor Operator performing the change.
     * @param int $teamId Team whose override is removed.
     * @param string $reason Non-empty justification.
     * @return bool Whether an override existed and was removed.
     * @throws AuthorizationException When the actor is no longer an operator.
     * @throws ValidationException When the reason is invalid.
     */
    public function clear(User $actor, int $teamId, string $reason): bool
    {
        $reason = $this->reason($reason);

        return $this->locked($actor, $teamId, function (Team $team) use ($actor, $reason): bool {
            $override = TeamPlanOverride::query()->where('team_id', $team->id)->first();

            if ($override === null) {
                return false;
            }

            $before = self::snapshot($override);
            $override->delete();

            PlatformAuditEvent::record($actor, 'team.plan_override.cleared', $team, ['before' => $before, 'after' => null], $reason);

            return true;
        });
    }

    /**
     * @template TResult
     * @param User $actor Operator re-authorized under the lock.
     * @param int $teamId Team to lock.
     * @param \Closure(Team): TResult $operation Work performed while holding the Team row lock.
     * @return TResult Operation result.
     * @throws AuthorizationException When the actor is no longer an operator.
     */
    private function locked(User $actor, int $teamId, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $teamId, $operation): mixed {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);

            Gate::forUser($actor)->authorize(PlatformAccess::GATE);

            return $operation($team);
        });
    }

    /**
     * @param PlanOverrideKind $kind Requested kind.
     * @param Plan|null $plan Requested granted plan.
     * @param CarbonImmutable|null $expiresAt Requested end.
     * @throws ValidationException On `plan` or `expires_at` when the combination cannot be stored.
     */
    private function assertCoherent(PlanOverrideKind $kind, ?Plan $plan, ?CarbonImmutable $expiresAt): void
    {
        if ($kind === PlanOverrideKind::Comped && !in_array($plan, [Plan::Pro, Plan::Business], true)) {
            throw ValidationException::withMessages(['plan' => 'Choose Pro or Business for a granted plan.']);
        }

        if ($kind === PlanOverrideKind::Unlimited && $plan !== null) {
            throw ValidationException::withMessages(['plan' => 'Unlimited testing does not take a plan.']);
        }

        if ($expiresAt !== null && !$expiresAt->isFuture()) {
            throw ValidationException::withMessages(['expires_at' => 'The end must be in the future.']);
        }
    }

    /**
     * @param string $reason Submitted justification.
     * @return string Trimmed justification.
     * @throws ValidationException On `reason` when it is empty or too long.
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of at most '.self::REASON_MAX_LENGTH.' characters.']);
        }

        return $reason;
    }

    /**
     * @param TeamPlanOverride $override Persisted or pending override.
     * @return array{kind: string, plan: string|null, expires_at: string|null} Comparable, audit-safe values.
     */
    private static function snapshot(TeamPlanOverride $override): array
    {
        return [
            'kind' => $override->kind->value,
            'plan' => $override->plan?->value,
            'expires_at' => $override->expires_at?->toIso8601ZuluString(),
        ];
    }
}
