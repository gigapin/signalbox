<?php

/** Validate the application-owned password and second-factor controller adapters. */

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Select a small, explicit validation contract from the declared route name. */
class SecurityInputRequest extends FormRequest
{
    /** @return bool Route middleware establishes guest, authenticated and verified states. */
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize reset email without changing passwords, tokens or second-factor codes. */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim($this->string('email')->toString()))]);
        }
    }

    /** @return array<string, list<string>> Required input for the selected authentication transition. */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'password.update' => [
                'email' => ['required', 'email', 'max:255'],
                'token' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            ],
            'password.confirm.store' => ['password' => ['required', 'string', 'max:128']],
            'two-factor.confirm' => ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']],
            default => [],
        };
    }
}
