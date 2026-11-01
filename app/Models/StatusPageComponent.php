<?php

declare(strict_types=1);

/** Persist one named group of monitors shown on a status page. */

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ManageStatusPages replaces all the components of a page on each save, so identities are not stable across saves.
 *
 * @property int $id Component identity.
 * @property int $team_id Immutable owning tenant, equal to the page's.
 * @property int $status_page_id Owning page.
 * @property string $name Public label, unique per page ignoring case.
 * @property int $position Zero-based display order.
 * @property CarbonImmutable|null $created_at UTC creation timestamp.
 * @property CarbonImmutable|null $updated_at UTC modification timestamp.
 */
final class StatusPageComponent extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @var list<string> Components are written explicitly by the owning action. */
    protected $guarded = ['*'];

    /** @return array<string, string> Integer keys and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'status_page_id' => 'integer',
            'position' => 'integer',
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

    /** @return BelongsTo<StatusPage, $this> Owning page. */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /** @return BelongsToMany<Monitor, $this> Grouped monitors; composite keys keep them in the page's team. */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'status_page_component_monitors');
    }
}
