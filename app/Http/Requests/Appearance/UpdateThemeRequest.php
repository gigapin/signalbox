<?php

/** Validate the authenticated account's explicit appearance choice. */

namespace App\Http\Requests\Appearance;

use App\Services\Appearance\UiTheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Only a theme may select appearance; identity always comes from the session. */
final class UpdateThemeRequest extends FormRequest
{
    /** @return bool Whether the session is authenticated. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> Closed theme input contract. */
    public function rules(): array
    {
        return ['theme' => ['required', 'string', Rule::in(UiTheme::values())]];
    }
}
