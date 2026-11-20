<?php

/** Validate bounded directory searches and the closed set of optional team panels. */

namespace App\Http\Requests\Teams;

use Illuminate\Foundation\Http\FormRequest;

/** Authorization remains a fresh membership and policy check before any projection. */
final class ListTeamsRequest extends FormRequest
{
    /** @return bool Verified web middleware establishes the actor. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> Bounded directory and selected-panel inputs. */
    public function rules(): array
    {
        $rules = [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];

        return $this->routeIs('teams.index') ? $rules + [
            'team_id' => ['required_with:panel', 'integer', 'min:1'],
            'panel' => ['required_with:team_id', 'in:plan,invite,access'],
        ] : $rules;
    }
}
