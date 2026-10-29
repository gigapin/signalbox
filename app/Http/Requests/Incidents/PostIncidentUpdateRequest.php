<?php

declare(strict_types=1);

/** Validate a new timeline entry: the status after the entry and its message. */

namespace App\Http\Requests\Incidents;

use App\Actions\Incidents\ManageIncidents;
use App\Enums\Incidents\IncidentStatus;
use Illuminate\Validation\Rule;

/** Whether the status change is admitted for the current incident is decided by ManageIncidents under lock. */
final class PostIncidentUpdateRequest extends IncidentChangeRequest
{
    /** @return array<string, mixed> Known status and a bounded nonblank message. */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(IncidentStatus::class)],
            'message' => ['required', 'string', 'max:'.ManageIncidents::TEXT_MAX_LENGTH],
        ];
    }

    /** @return IncidentStatus Validated status after the entry. */
    public function status(): IncidentStatus
    {
        return IncidentStatus::from($this->string('status')->toString());
    }

    /** @return string Validated message; the action trims it again. */
    public function message(): string
    {
        return $this->string('message')->toString();
    }
}
