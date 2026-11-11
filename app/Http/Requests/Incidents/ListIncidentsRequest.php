<?php

declare(strict_types=1);

/** Authorize an incident list read and parse its query string into a validated filter. */

namespace App\Http\Requests\Incidents;

use App\Data\Incidents\IncidentListFilter;
use App\Data\Incidents\IncidentListSelection;
use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Like the monitor list, invalid values are dropped and reported instead of failing validation, because a
 * failed GET validation would redirect to the same invalid URL.
 */
final class ListIncidentsRequest extends FormRequest
{
    /** @var list<string> Query keys that carried unusable values, in declaration order. */
    private array $rejected = [];

    /** @return bool Whether the actor has any current membership in the route team. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Incident::class);
    }

    /** @return array<string, mixed> No framework rules; filter() applies the per-key tolerant parsing. */
    public function rules(): array
    {
        return [];
    }

    /** @return IncidentListFilter Accepted values only; rejected keys are available from rejectedFilters(). */
    public function filter(): IncidentListFilter
    {
        $selection = IncidentListSelection::fromInput($this->query->all());
        $this->rejected = $selection->rejected;

        return $selection->filter;
    }

    /** @return list<string> Keys ignored by the latest filter() call because their values were invalid. */
    public function rejectedFilters(): array
    {
        return $this->rejected;
    }

}
