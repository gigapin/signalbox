<?php

declare(strict_types=1);

/** Authorize an incident list read and parse its query string into a validated filter. */

namespace App\Http\Requests\Incidents;

use App\Data\Incidents\IncidentListFilter;
use App\Enums\Incidents\IncidentListState;
use App\Enums\Incidents\IncidentSeverity;
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
        $this->rejected = [];

        return new IncidentListFilter(
            state: $this->enumValue('state', IncidentListState::class),
            severity: $this->enumValue('severity', IncidentSeverity::class),
            page: $this->pageNumber(),
        );
    }

    /** @return list<string> Keys ignored by the latest filter() call because their values were invalid. */
    public function rejectedFilters(): array
    {
        return $this->rejected;
    }

    /**
     * @template TEnum of IncidentListState|IncidentSeverity
     *
     * @param string $key Query key holding a backed enum value.
     * @param class-string<TEnum> $enum Accepted enum.
     * @return TEnum|null Matching case, or null when absent or unsupported.
     */
    private function enumValue(string $key, string $enum): IncidentListState|IncidentSeverity|null
    {
        $value = $this->query($key);

        if ($value === null) {
            return null;
        }

        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            $this->rejected[] = $key;
        }

        return $case;
    }

    /** @return int Positive page number; invalid values fall back to the first page. */
    private function pageNumber(): int
    {
        $value = $this->query('page');

        if ($value === null) {
            return 1;
        }

        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,8}$/D', $value) !== 1) {
            $this->rejected[] = 'page';

            return 1;
        }

        return (int) $value;
    }
}
