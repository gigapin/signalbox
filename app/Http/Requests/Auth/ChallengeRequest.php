<?php

/** Restrict challenge input while retaining Fortify's session-based user resolution. */

namespace App\Http\Requests\Auth;

use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/** Require exactly one authenticator token or recovery code. */
class ChallengeRequest extends TwoFactorLoginRequest
{
    /** @return array<string, list<string>> Mutually exclusive second-factor inputs with bounded lengths. */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return [
            'code' => ['nullable', 'required_without:recovery_code', 'prohibits:recovery_code', 'string', 'regex:/\A[0-9]{6}\z/'],
            'recovery_code' => ['nullable', 'required_without:code', 'prohibits:code', 'string', 'max:255'],
        ];
    }
}
