<?php

declare(strict_types=1);

/** Parse bounded metric controls without redirecting malformed private GET URLs to themselves. */

namespace App\Http\Requests\Monitoring;

use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Authorization remains tied to the route-bound monitor, never a query identity. */
final class ShowMonitorRequest extends FormRequest
{
    /** @return bool Whether this actor may read the route monitor. */
    public function authorize(): bool
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor && Gate::allows('view', $monitor);
    }

    /** @return array<string, mixed> GET values use explicit allowlists instead of redirecting validation. */
    public function rules(): array
    {
        return [];
    }

    /** @return array{period: string, region: string, rejected: list<string>} Metric selection; invalid values are never reflected. */
    public function selection(): array
    {
        $rejected = [];
        $period = $this->accepted('period', ['24h', '7d', '30d'], '24h', $rejected);
        $region = $this->accepted('region', ['all', 'eu', 'us'], 'all', $rejected);

        return ['period' => $period, 'region' => $region, 'rejected' => $rejected];
    }

    /**
     * @param string $key Query parameter name.
     * @param list<string> $allowed Complete accepted values.
     * @param string $default Absent or malformed value's fallback.
     * @param list<string> $rejected Output containing names only.
     * @return string Accepted value, never arbitrary input.
     */
    private function accepted(string $key, array $allowed, string $default, array &$rejected): string
    {
        $value = $this->query($key);
        if ($value === null) {
            return $default;
        }
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }
        $rejected[] = $key;

        return $default;
    }
}
