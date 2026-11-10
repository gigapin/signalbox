<?php

declare(strict_types=1);

/** Authorize a monitor list read and parse its query string into a validated filter. */

namespace App\Http\Requests\Monitoring;

use App\Data\Monitoring\MonitorListFilter;
use App\Data\Monitoring\MonitorListSelection;
use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Invalid filter values are dropped and reported instead of failing validation: a failed GET validation
 * redirects to the previous URL, which for a directly opened list is the same invalid URL and would loop.
 * The page therefore renders the remaining filters together with an explicit error state.
 */
final class ListMonitorsRequest extends FormRequest
{
    /** @var list<string> Query keys that carried unusable values, in declaration order. */
    private array $rejected = [];

    /** @return bool Whether the actor has any current membership in the route team. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
    }

    /** @return array<string, mixed> No framework rules; filter() applies the per-key tolerant parsing. */
    public function rules(): array
    {
        return [];
    }

    /** @return MonitorListFilter Accepted values only; rejected keys are available from rejectedFilters(). */
    public function filter(): MonitorListFilter
    {
        $selection = MonitorListSelection::fromInput($this->query());
        $this->rejected = $selection->rejected;

        return $selection->filter;
    }

    /** @return list<string> Keys ignored by the latest filter() call because their values were invalid. */
    public function rejectedFilters(): array
    {
        return $this->rejected;
    }

}
