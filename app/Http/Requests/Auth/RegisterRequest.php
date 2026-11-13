<?php

/** Validate registration fields before any identity aggregate is persisted. */

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Accept public account data without exposing ownership, plan or trial assignment. */
class RegisterRequest extends FormRequest
{
    /**
     * @var string Allowed team names: letters, marks, digits, spaces and `- & ' ’ , ( )`, plus a dot only before a
     *             space or at the end ("Acme Inc."). The name is sent in invitation emails to unproved addresses, so
     *             it cannot carry URLs, email addresses, Markdown links or line breaks.
     */
    public const string TEAM_NAME_PATTERN = "/^(?:[\\p{L}\\p{M}\\p{N} &'’,()-]|\\.(?= |$))+$/u";

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
            'team_name' => ['required', 'string', 'max:255', 'regex:'.self::TEAM_NAME_PATTERN],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }

    /** @return array<string, string> Explanation of the team name characters, shown next to the field. */
    public function messages(): array
    {
        return [
            'team_name.regex' => 'Use letters, numbers, spaces and - & \' , ( ) in the team name; a dot is allowed only at the end of a word.',
        ];
    }
}
