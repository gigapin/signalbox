<?php

declare(strict_types=1);

/** Authorize downloading a team's monthly report. */

namespace App\Http\Requests\Reports;

use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Anyone who can read the team's monitors can read their report; the month is validated by the route pattern. */
final class ShowMonthlyReportRequest extends FormRequest
{
    /** @return bool Whether the current actor may view monitors in the established route team. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
    }

    /** @return array<string, mixed> No body or query input is read. */
    public function rules(): array
    {
        return [];
    }
}
