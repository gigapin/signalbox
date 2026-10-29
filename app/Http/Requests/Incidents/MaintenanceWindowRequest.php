<?php

declare(strict_types=1);

/** Validate the interval and reason of a maintenance window being scheduled or rescheduled. */

namespace App\Http\Requests\Incidents;

use App\Actions\Incidents\ManageMaintenanceWindows;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Instants must carry an explicit UTC offset (user decision: the browser converts its local input), so the
 * server never guesses a timezone. Past starts and end-before-start are refused by ManageMaintenanceWindows
 * against its own clock under lock; this request only checks the syntax.
 */
final class MaintenanceWindowRequest extends FormRequest
{
    /** @var string ISO-8601 date and time with optional seconds and fraction and a mandatory `Z` or `±hh:mm` offset. */
    private const string INSTANT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D';

    /** @return bool Whether the actor may manage the route monitor, which includes its maintenance. */
    public function authorize(): bool
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor && Gate::allows('update', $monitor);
    }

    /** @return array<string, mixed> Optional start (absent means now), required end and a bounded reason. */
    public function rules(): array
    {
        return [
            'starts_at' => ['nullable', 'string', 'regex:'.self::INSTANT_PATTERN, 'date'],
            'ends_at' => ['required', 'string', 'regex:'.self::INSTANT_PATTERN, 'date'],
            'reason' => ['required', 'string', 'max:'.ManageMaintenanceWindows::REASON_MAX_LENGTH],
        ];
    }

    /** @return array<string, string> Readable messages instead of the raw pattern rule. */
    public function messages(): array
    {
        return [
            'starts_at.regex' => 'The start must be a date and time with a timezone offset.',
            'ends_at.regex' => 'The end must be a date and time with a timezone offset.',
        ];
    }

    /** @return CarbonImmutable|null Validated UTC start, or null to start now. */
    public function startsAt(): ?CarbonImmutable
    {
        $value = $this->input('starts_at');

        return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
    }

    /** @return CarbonImmutable Validated exclusive UTC end. */
    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_at')->toString())->utc();
    }

    /** @return string Validated reason; the action trims it again. */
    public function reason(): string
    {
        return $this->string('reason')->toString();
    }
}
