<?php

/** Validate registration fields before any identity aggregate is persisted. */

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Accept public account data without exposing ownership, plan or trial assignment. */
class RegisterRequest extends FormRequest
{
    /** @return bool Guest-only route middleware supplies the authorization boundary. */
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize transport input consistently with login and PostgreSQL uniqueness. */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim($this->string('email')->toString())), 'name' => trim($this->string('name')->toString()), 'team_name' => trim($this->string('team_name')->toString())]);
    }

    /** @return array<string, list<string>> Registration shape and local, network-free password policy. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'team_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }
}
