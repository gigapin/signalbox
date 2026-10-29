<?php

declare(strict_types=1);

/** Persist a named, ordered list of escalation steps of one team. */

namespace App\Models;

use App\Data\Notifications\EscalationStep;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Database\Factories\EscalationPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Written by ManageEscalationPolicies, which validates the steps and their channels under the Team lock. At most
 * one policy per team is the default, used by monitors without an override (EscalationPolicyResolver).
 *
 * @property int $id Policy identity.
 * @property int $team_id Immutable owning tenant.
 * @property string $name Nonblank label, unique per team ignoring case.
 * @property list<array{after_minutes: int, channel_ids: list<int>}> $steps_json Ordered steps.
 * @property bool $is_default Whether monitors without an override use this policy.
 * @property int|null $created_by Creating user; null when deleted.
 * @property CarbonImmutable|null $created_at UTC creation timestamp.
 * @property CarbonImmutable|null $updated_at UTC modification timestamp.
 */
final class EscalationPolicy extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @use HasFactory<EscalationPolicyFactory> Synthetic policies for tests. */
    use HasFactory;

    /** @var list<string> Policies are written explicitly by the owning action. */
    protected $guarded = ['*'];

    /** @var array<string, mixed> New policies are not the default. */
    protected $attributes = [
        'is_default' => false,
    ];

    /** @return array<string, string> JSON steps, boolean flag and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'steps_json' => 'array',
            'is_default' => 'boolean',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Normalize instants before Laravel formats timestamp values without a timezone offset.
     *
     * @param mixed $value Date value accepted by Eloquent, including null and empty attributes.
     * @return string|null UTC timestamp for PostgreSQL, preserving null and empty values.
     */
    public function fromDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    /** @return HasMany<Monitor, $this> Monitors that override the team default with this policy. */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /** @return list<EscalationStep> Typed steps in delay order. */
    public function steps(): array
    {
        return array_map(
            static fn (array $step): EscalationStep => new EscalationStep((int) $step['after_minutes'], array_map(intval(...), $step['channel_ids'])),
            $this->steps_json,
        );
    }

    /**
     * @param list<EscalationStep> $steps Validated steps.
     * @return $this Policy with the steps assigned but not saved.
     */
    public function assignSteps(array $steps): static
    {
        $this->steps_json = array_map(static fn (EscalationStep $step): array => $step->toArray(), $steps);

        return $this;
    }

    /** @return list<int> Distinct channel identities referenced by any step. */
    public function channelIds(): array
    {
        $ids = [];

        foreach ($this->steps() as $step) {
            $ids = [...$ids, ...$step->channelIds];
        }

        return array_values(array_unique($ids));
    }
}
