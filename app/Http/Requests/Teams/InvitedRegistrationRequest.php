<?php

/** Validate invited registration while retaining ordinary account credential rules. */

namespace App\Http\Requests\Teams;

use App\Http\Requests\Auth\RegisterRequest;

/** A valid server-owned invitation replaces the personal-team input. */
final class InvitedRegistrationRequest extends RegisterRequest
{
    /** @return array<string, list<string>> Existing name/email/password validation with no team creation field. */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['team_name']);

        return $rules;
    }
}
