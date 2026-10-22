<?php

/** Validate only the transport inputs declared by membership-management routes. */

namespace App\Http\Requests\Teams;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Delegate team/role authorization to actions that recheck it under lock. */
final class TeamInputRequest extends FormRequest
{
    /** @return bool Authentication and verification middleware establish identity, actions enforce roles. */
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize email without trusting a submitted team, actor, plan or ownership value. */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim($this->string('email')->toString()))]);
        }
    }

    /** @return array<string, list<string>> Minimal input shape for each explicit transition. */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'teams.invitations.store' => [
                'email' => ['required', 'string', 'email', 'max:255'],
                'role' => ['required', 'in:owner,admin,member,viewer']
            ],
            'teams.members.update' => ['role' => ['required', 'in:owner,admin,member,viewer']],
            'teams.ownership.store' => ['user_id' => ['required', 'integer', 'min:1']],
            default => [],
        };
    }
}
