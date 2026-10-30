<?php

declare(strict_types=1);

/** Authorize a dashboard read and tolerate malformed GET filters without a self-redirect loop. */

namespace App\Http\Requests\Dashboard;

use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Tenant identity comes only from route middleware; posted/query team and actor IDs cannot grant authority. */
final class ShowDashboardRequest extends FormRequest
{
    /** @var list<string> Invalid query keys reported to the browser, never raw values. */
    private array $rejected = [];

    /** @return bool Whether the current actor may view monitors in the established route team. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
    }

    /** @return array<string, mixed> GET filters are parsed explicitly to avoid redirect loops. */
    public function rules(): array
    {
        return [];
    }

    /** @return array{monitorId: int|null, page: int, rejected: list<string>} Bounded accepted values; positive inaccessible IDs still fail as 404 in the read service. */
    public function selection(): array
    {
        $this->rejected = [];
        $monitor = $this->positive('monitor', PHP_INT_MAX);
        $page = $this->positive('page', 1_000_000) ?? 1;

        return ['monitorId' => $monitor, 'page' => $page, 'rejected' => $this->rejected];
    }

    /**
     * @param string $key Query key containing a canonical positive decimal integer.
     * @param int $maximum Inclusive operational bound.
     * @return int|null Accepted integer, or null for absent/invalid input; only malformed values are reported.
     */
    private function positive(string $key, int $maximum): ?int
    {
        $value = $this->query($key);

        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1) {
            $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);

            if ($number !== false) {
                return $number;
            }
        }

        $this->rejected[] = $key;

        return null;
    }
}
