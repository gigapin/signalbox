<?php

declare(strict_types=1);

/** Persist a team's public status page, optional password and revocable custom hostname claim. */

namespace App\Models;

use App\Data\StatusPages\StatusPageTheme;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Written by ManageStatusPages and ManageStatusDomains. Public slug/host discovery belongs to PublicStatusPages
 * and StatusDomainRegistry; visitor responses project explicit fields only.
 *
 * @property int $id Page identity.
 * @property int $team_id Immutable owning tenant.
 * @property string $slug Public address segment, unique across all teams.
 * @property string $title Page heading.
 * @property string|null $description Optional introduction.
 * @property array{mode: string, accent: string} $theme_json Appearance, values from closed enums.
 * @property bool $is_public Whether the public address answers; false is a draft.
 * @property string|null $password_hash One-way hash; null when the page has no password.
 * @property string|null $custom_domain Pending or active canonical customer hostname.
 * @property string|null $domain_challenge Public random DNS proof generation, hidden from ordinary serialization.
 * @property CarbonImmutable|null $domain_checked_at Last reserved DNS check in UTC.
 * @property int|null $created_by Creating user; null when deleted.
 * @property CarbonImmutable|null $created_at UTC creation timestamp.
 * @property CarbonImmutable|null $updated_at UTC modification timestamp.
 */
final class StatusPage extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @use HasFactory<StatusPageFactory> Synthetic pages for tests. */
    use HasFactory;

    /** @var string Accepted address: 3 to 63 lowercase letters, digits and inner hyphens, matching the database check. */
    public const string SLUG_PATTERN = '[a-z0-9][a-z0-9-]{1,61}[a-z0-9]';

    /** @var list<string> Pages are written explicitly by the owning action. */
    protected $guarded = ['*'];

    /** @var list<string> Password hashes stay private; DNS proof generations require an explicit management projection. */
    protected $hidden = ['password_hash', 'domain_challenge'];

    /** @var array<string, mixed> New pages are drafts. */
    protected $attributes = [
        'is_public' => false,
    ];

    /** @return array<string, string> JSON theme, boolean flag and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'theme_json' => 'array',
            'is_public' => 'boolean',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'domain_checked_at' => 'immutable_datetime',
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

    /** @return HasMany<StatusPageComponent, $this> Components in display order. */
    public function components(): HasMany
    {
        return $this->hasMany(StatusPageComponent::class)->orderBy('position');
    }

    /** @return StatusPageTheme Typed appearance. */
    public function theme(): StatusPageTheme
    {
        return new StatusPageTheme(
            StatusPageThemeMode::from($this->theme_json['mode']),
            StatusPageAccent::from($this->theme_json['accent']),
        );
    }
}
