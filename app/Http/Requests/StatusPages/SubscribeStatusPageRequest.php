<?php

declare(strict_types=1);

/** Validate an anonymous mailbox subscription without contacting DNS or an email provider. */

namespace App\Http\Requests\StatusPages;

use Illuminate\Foundation\Http\FormRequest;

/** The service rechecks page visibility and the current session password under the Team lock. */
final class SubscribeStatusPageRequest extends FormRequest
{
    /** @return bool Mailbox ownership is established later through double opt-in. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> A bounded RFC mailbox; validation never performs network I/O. */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:254']];
    }
}
