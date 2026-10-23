<?php

declare(strict_types=1);

/** Record one platform operator action; events are append-only. */

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Changes hold before/after values of non-secret fields only. Saving an existing event or deleting one
 * throws, so the trail can only grow through the application.
 *
 * @property int $id Event identity, also the chronological order within a subject.
 * @property int|null $actor_id Operator; null for command-line actions or after the user is deleted.
 * @property string $action Dotted action name, e.g. `team.plan_override.granted`.
 * @property string $subject_type Subject kind, `team` or `user`.
 * @property int $subject_id Subject identity, kept after the subject is deleted.
 * @property array<string, mixed> $changes Before/after snapshot of the changed fields.
 * @property string|null $reason Operator justification.
 * @property CarbonImmutable $created_at Event instant in UTC.
 */
final class PlatformAuditEvent extends Model
{
    /** Events have a creation time only. */
    public const UPDATED_AT = null;

    /** @var list<string> $guarded Every field requires explicit trusted assignment. */
    protected $guarded = ['*'];

    /** Refuse updates and deletes on persisted events. */
    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Platform audit events are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Platform audit events are append-only.'));
    }

    /**
     * @param User|null $actor Operator, or null for command-line actions.
     * @param string $action Dotted action name.
     * @param Team|User $subject Team or user the action applied to.
     * @param array<string, mixed> $changes Before/after values without secrets.
     * @param string|null $reason Operator justification.
     * @return self Persisted event.
     */
    public static function record(?User $actor, string $action, Team|User $subject, array $changes, ?string $reason = null): self
    {
        $event = new self();
        $event->forceFill([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject instanceof Team ? 'team' : 'user',
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
            'reason' => $reason,
        ])->save();

        return $event;
    }

    /** @return BelongsTo<User, $this> Operator who acted. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> JSON changes and immutable UTC creation time. */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
