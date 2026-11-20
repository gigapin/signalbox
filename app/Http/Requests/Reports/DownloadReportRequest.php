<?php

declare(strict_types=1);

/** Authorize and validate a complete date range for a team report. */

namespace App\Http\Requests\Reports;

use App\Models\Monitor;
use App\Models\Team;
use App\Services\Reports\ReportCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Reject unretained or incomplete local dates before rendering a PDF. */
final class DownloadReportRequest extends FormRequest
{
    /** @return bool Whether the current actor may view monitors in the established route team. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
    }

    /** @return array<string, mixed> Inclusive dates checked against the current effective plan. */
    public function rules(): array
    {
        $team = Team::query()->findOrFail((int) $this->route('team'));
        $bounds = app(ReportCalendar::class)->forTeam($team);
        return [
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$bounds['min'], 'before_or_equal:'.$bounds['max']],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$bounds['max']],
        ];
    }
}
