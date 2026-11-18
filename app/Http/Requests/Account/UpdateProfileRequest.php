<?php

/** Validate optional account input and require the current password only for a new email request. */

namespace App\Http\Requests\Account;

use App\Services\Account\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validate owner-submitted profile and identity confirmation inputs. */
final class UpdateProfileRequest extends FormRequest
{
    /** @return bool Authentication is also enforced by route middleware. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
    /** @return array<string, mixed> Optional fields, bounded raster upload and email identity confirmation. */
    public function rules(): array
    {
        $email = strtolower(trim($this->string('email')->toString()));
        $changedEmail = $email !== $this->user()?->email
            && $email !== $this->user()?->pending_email;

        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', Rule::in(Countries::codes())],
            'company_name' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'avatar' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=512,max_height=512',
            ],
            'remove_avatar' => ['boolean'],
            'current_password' => [Rule::requiredIf($changedEmail), 'nullable', 'current_password:web'],
        ];
    }
}
