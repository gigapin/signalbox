<?php

declare(strict_types=1);

/** Validate the cause declared by people, or its removal. */

namespace App\Http\Requests\Incidents;

use App\Actions\Incidents\ManageIncidents;

/** Empty input arrives as null through the global middleware and clears the declared cause. */
final class DeclareRootCauseRequest extends IncidentChangeRequest
{
    /** @return array<string, mixed> Optional bounded note; the key itself must be present. */
    public function rules(): array
    {
        return [
            'root_cause_note' => ['present', 'nullable', 'string', 'max:'.ManageIncidents::TEXT_MAX_LENGTH],
        ];
    }

    /** @return string|null Validated note, or null to clear it. */
    public function note(): ?string
    {
        $note = $this->input('root_cause_note');

        return is_string($note) ? $note : null;
    }
}
