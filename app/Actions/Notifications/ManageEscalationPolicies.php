<?php

declare(strict_types=1);

/** Create, change and delete escalation policies under fresh policy checks and the Team lock. */

namespace App\Actions\Notifications;

use App\Data\Notifications\EscalationStep;
use App\Exceptions\EscalationPolicyRejected;
use App\Models\EscalationPolicy;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Steps are absolute delays from the incident confirmation, strictly increasing, each notifying distinct
 * channels of the same team. Inactive channels may be referenced (deliveries skip them) so a policy can be
 * prepared before verification. Channel deletion and policy changes share the Team lock, so a policy never
 * keeps a reference to a channel deleted concurrently.
 */
final readonly class ManageEscalationPolicies
{
    /** @var int Longest policy label in characters, matching the database check. */
    public const int NAME_MAX_LENGTH = 100;

    /**
     * @param TenantContext $context Explicit tenant boundary for each change.
     * @param Repository $config Operational bounds in `notifications.*`.
     */
    public function __construct(private TenantContext $context, private Repository $config)
    {
    }

    /**
     * @param User $actor Owner or admin creating the policy.
     * @param int $teamId Active team.
     * @param string $name Label unique per team ignoring case.
     * @param list<array{after_minutes: int, channel_ids: list<int>}> $steps Submitted steps.
     * @param bool $isDefault Whether the policy becomes the team default, replacing the previous one.
     * @return EscalationPolicy Created policy.
     * @throws EscalationPolicyRejected When the label, steps or limits are violated.
     */
    public function create(User $actor, int $teamId, string $name, array $steps, bool $isDefault): EscalationPolicy
    {
        return $this->locked($teamId, function () use ($actor, $teamId, $name, $steps, $isDefault): EscalationPolicy {
            Gate::forUser($actor)->authorize('create', EscalationPolicy::class);
            $limit = (int) $this->config->get('notifications.policies_per_team');

            if (EscalationPolicy::query()->count() >= $limit) {
                throw new EscalationPolicyRejected('A team can configure at most '.$limit.' escalation policies.');
            }

            $policy = new EscalationPolicy();
            $policy->forceFill(['created_by' => $actor->id]);

            return $this->store($policy, $teamId, $name, $steps, $isDefault);
        });
    }

    /**
     * Monitors keep their override; the new steps apply to notifications scheduled after the change.
     *
     * @param User $actor Owner or admin changing the policy.
     * @param int $teamId Active team.
     * @param int $policyId Policy of that team.
     * @param string $name Replacement label.
     * @param list<array{after_minutes: int, channel_ids: list<int>}> $steps Replacement steps.
     * @param bool $isDefault Whether the policy is the team default afterwards.
     * @return EscalationPolicy Updated policy.
     * @throws EscalationPolicyRejected When the label or steps are invalid.
     */
    public function update(User $actor, int $teamId, int $policyId, string $name, array $steps, bool $isDefault): EscalationPolicy
    {
        return $this->locked($teamId, function () use ($actor, $teamId, $policyId, $name, $steps, $isDefault): EscalationPolicy {
            $policy = EscalationPolicy::query()->lockForUpdate()->findOrFail($policyId);
            Gate::forUser($actor)->authorize('update', $policy);

            return $this->store($policy, $teamId, $name, $steps, $isDefault);
        });
    }

    /**
     * Monitors that overrode the default with this policy fall back to the team default (foreign key).
     *
     * @param User $actor Owner or admin deleting the policy.
     * @param int $teamId Active team.
     * @param int $policyId Policy of that team.
     */
    public function delete(User $actor, int $teamId, int $policyId): void
    {
        $this->locked($teamId, function () use ($actor, $policyId): void {
            $policy = EscalationPolicy::query()->lockForUpdate()->findOrFail($policyId);
            Gate::forUser($actor)->authorize('delete', $policy);
            $policy->delete();
        });
    }

    /**
     * @param EscalationPolicy $policy New or locked existing policy.
     * @param int $teamId Active team.
     * @param string $name Submitted label.
     * @param list<array{after_minutes: int, channel_ids: list<int>}> $steps Submitted steps.
     * @param bool $isDefault Requested default flag.
     * @return EscalationPolicy Saved policy.
     * @throws EscalationPolicyRejected When the label or steps are invalid.
     */
    private function store(EscalationPolicy $policy, int $teamId, string $name, array $steps, bool $isDefault): EscalationPolicy
    {
        $label = $this->name($policy, $name);
        $validated = $this->steps($steps);

        // The partial unique index admits one default per team, so the previous default is cleared first.
        if ($isDefault) {
            EscalationPolicy::query()
                ->where('team_id', $teamId)
                ->where('is_default', true)
                ->when($policy->exists, static fn ($query) => $query->whereKeyNot($policy->id))
                ->update(['is_default' => false]);
        }

        $policy->forceFill(['name' => $label, 'is_default' => $isDefault])->assignSteps($validated)->save();

        return $policy;
    }

    /**
     * @param list<array{after_minutes: int, channel_ids: list<int>}> $steps Submitted steps.
     * @return list<EscalationStep> Validated steps referencing only channels of the active team.
     * @throws EscalationPolicyRejected When counts, delays or channels are invalid.
     */
    private function steps(array $steps): array
    {
        $maxSteps = (int) $this->config->get('notifications.steps_per_policy');
        $maxChannels = (int) $this->config->get('notifications.channels_per_step');
        $maxDelay = (int) $this->config->get('notifications.max_step_delay_minutes');

        if ($steps === [] || count($steps) > $maxSteps) {
            throw new EscalationPolicyRejected('A policy needs between 1 and '.$maxSteps.' steps.');
        }

        $validated = [];
        $previous = -1;

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $delay = $step['after_minutes'];
            $channels = array_values(array_unique($step['channel_ids']));

            if ($delay < 0 || $delay > $maxDelay) {
                throw new EscalationPolicyRejected('Step '.$number.' must wait between 0 and '.$maxDelay.' minutes.');
            }

            if ($delay <= $previous) {
                throw new EscalationPolicyRejected('Step '.$number.' must wait longer than the step before it.');
            }

            if ($channels === [] || count($channels) > $maxChannels) {
                throw new EscalationPolicyRejected('Step '.$number.' must notify between 1 and '.$maxChannels.' channels.');
            }

            $validated[] = new EscalationStep($delay, $channels);
            $previous = $delay;
        }

        $referenced = array_values(array_unique(array_merge(...array_map(static fn (EscalationStep $step): array => $step->channelIds, $validated))));

        // The tenant scope hides another team's channels, so a foreign identity is simply not found.
        if (NotificationChannel::query()->whereKey($referenced)->count() !== count($referenced)) {
            throw new EscalationPolicyRejected('Every step must notify existing channels of this team.');
        }

        return $validated;
    }

    /**
     * @param EscalationPolicy $policy Policy being saved; its own current name does not collide.
     * @param string $value Submitted label.
     * @return string Trimmed label, unique per team ignoring case.
     * @throws EscalationPolicyRejected When the label is blank, too long or already used.
     */
    private function name(EscalationPolicy $policy, string $value): string
    {
        $text = trim($value);

        if ($text === '' || mb_strlen($text) > self::NAME_MAX_LENGTH) {
            throw new EscalationPolicyRejected('A policy name must be between 1 and '.self::NAME_MAX_LENGTH.' characters.');
        }

        $taken = EscalationPolicy::query()
            ->whereRaw('lower(name) = lower(?)', [$text])
            ->when($policy->exists, static fn ($query) => $query->whereKeyNot($policy->id))
            ->exists();

        if ($taken) {
            throw new EscalationPolicyRejected('Another escalation policy already uses this name.');
        }

        return $text;
    }

    /**
     * @template TResult
     * @param int $teamId Active team, locked for the whole change.
     * @param Closure(): TResult $operation Change applied while the team is locked.
     * @return TResult Operation result.
     */
    private function locked(int $teamId, Closure $operation): mixed
    {
        return $this->context->run($teamId, static fn (): mixed => DB::transaction(static function () use ($teamId, $operation): mixed {
            Team::query()->lockForUpdate()->findOrFail($teamId);

            return $operation();
        }));
    }
}
