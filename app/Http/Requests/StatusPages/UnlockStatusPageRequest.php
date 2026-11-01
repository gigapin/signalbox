<?php

declare(strict_types=1);

/** Validate the password a visitor submits for a protected status page. */

namespace App\Http\Requests\StatusPages;

use Illuminate\Foundation\Http\FormRequest;

/** Open to everyone: the password is the authorization, and the route limits how many attempts can be made. */
final class UnlockStatusPageRequest extends FormRequest
{
    /** @return bool Always true for this public form. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> A bounded password, so hashing never receives unbounded input. */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
