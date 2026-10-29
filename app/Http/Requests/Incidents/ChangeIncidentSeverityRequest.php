<?php

declare(strict_types=1);

/** Validate a manual severity change. */

namespace App\Http\Requests\Incidents;

use App\Enums\Incidents\IncidentSeverity;
use Illuminate\Validation\Rule;

/** An unchanged severity is accepted and records nothing. */
final class ChangeIncidentSeverityRequest extends IncidentChangeRequest
{
    /** @return array<string, mixed> Known severity. */
    public function rules(): array
    {
        return [
            'severity' => ['required', 'string', Rule::enum(IncidentSeverity::class)],
        ];
    }

    /** @return IncidentSeverity Validated replacement severity. */
    public function severity(): IncidentSeverity
    {
        return IncidentSeverity::from($this->string('severity')->toString());
    }
}
