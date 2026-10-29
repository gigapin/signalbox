<?php

declare(strict_types=1);

/** Authorize a monitor list read and parse its query string into a validated filter. */

namespace App\Http\Requests\Monitoring;

use App\Data\Monitoring\MonitorListFilter;
use App\Enums\Monitoring\MonitorOperationalState;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\Monitoring\MonitorType;
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
        $this->rejected = [];

        return new MonitorListFilter(
            query: $this->searchText(),
            type: $this->enumValue('type', MonitorType::class),
            status: $this->enumValue('status', MonitorStatus::class),
            state: $this->enumValue('state', MonitorOperationalState::class),
            page: $this->pageNumber(),
        );
    }

    /** @return list<string> Keys ignored by the latest filter() call because their values were invalid. */
    public function rejectedFilters(): array
    {
        return $this->rejected;
    }

    /** @return string Trimmed search text, or empty when absent, not a string or too long. */
    private function searchText(): string
    {
        $value = $this->query('q');

        if ($value === null) {
            return '';
        }

        if (!is_string($value) || mb_strlen(trim($value)) > MonitorListFilter::QUERY_MAX_LENGTH) {
            $this->rejected[] = 'q';

            return '';
        }

        return trim($value);
    }

    /**
     * @template TEnum of MonitorType|MonitorStatus|MonitorOperationalState
     *
     * @param string $key Query key holding a backed enum value.
     * @param class-string<TEnum> $enum Accepted enum.
     * @return TEnum|null Matching case, or null when absent or unsupported.
     */
    private function enumValue(string $key, string $enum): MonitorType|MonitorStatus|MonitorOperationalState|null
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
