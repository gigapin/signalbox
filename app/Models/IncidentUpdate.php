<?php

declare(strict_types=1);

/** Persist one append-only incident timeline entry and its explicit public visibility. */

namespace App\Models;

use App\Enums\Incidents\IncidentStatus;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentUpdateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Entries are never edited; corrections are new entries. The only admitted change is a single publication.
 *
 * @property int $id Timeline entry identity.
 * @property int $team_id Immutable owning tenant, equal to the incident's team.
 * @property int $incident_id Owning incident.
 * @property int|null $author_id Writing user; null for system entries or deleted users.
 * @property IncidentStatus $status Incident status after this entry.
 * @property string $message Nonblank entry text.
 * @property bool $is_public Whether the entry was explicitly published for public status pages.
 * @property CarbonImmutable|null $published_at UTC publication instant; set exactly when public.
 * @property CarbonImmutable|null $created_at UTC creation instant.
 * @property-read Incident $incident Owning incident within the same tenant.
 * @property-read User|null $author Writing user, when any.
 */
final class IncidentUpdate extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @use HasFactory<IncidentUpdateFactory> Synthetic entries created only inside a tenant context. */
    use HasFactory;

    /** @var string|null Entries have no modification timestamp because they are append-only. */
    public const UPDATED_AT = null;

    /** @var list<string> Timeline writes require explicit assignment by the owning actions. */
    protected $guarded = ['*'];

    /** @var array<string, mixed> Entries stay private until explicitly published. */
    protected $attributes = [
        'is_public' => false,
    ];

    /** Mirror the database trigger so model code fails before issuing a rejected statement. */
    protected static function booted(): void
    {
        static::updating(static function (self $update): void {
            $update->guardPublicationOnly();
        });
    }

    /** @return array<string, string> Native status enum, scalar identities, visibility and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'incident_id' => 'integer',
            'author_id' => 'integer',
            'status' => IncidentStatus::class,
            'is_public' => 'boolean',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Incident, $this> Owning incident, resolved through the tenant scope. */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> Writing user; identity only, never an authorization grant. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @throws LogicException When the change is anything other than publishing a private entry. */
    private function guardPublicationOnly(): void
    {
        $changed = array_keys($this->getDirty());
        $publicationOnly = array_diff($changed, ['is_public', 'published_at']) === [];
        $wasPrivate = $this->getOriginal('is_public') === false;

        if ($publicationOnly && $wasPrivate && $this->is_public && $this->published_at !== null) {
            return;
        }

        throw new LogicException('Incident timeline entries are append-only; only a single publication is allowed.');
    }
}
