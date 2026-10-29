<?php

declare(strict_types=1);

/** Validate a verification code typed by a channel manager. */

namespace App\Http\Requests\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** The code is never flashed back (`code` is in the global dontFlash list); wrong codes are counted by ManageNotificationChannels. */
final class ConfirmChannelVerificationRequest extends FormRequest
{
    /** @return bool Whether the actor may manage the route channel. */
    public function authorize(): bool
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel && Gate::allows('update', $channel);
    }

    /** @return array<string, mixed> Exactly six digits. */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/^\d{6}$/D']];
    }

    /** @return array<string, string> Readable message instead of the raw pattern rule. */
    public function messages(): array
    {
        return ['code.regex' => 'Enter the 6-digit code from the verification message.'];
    }

    /** @return string Validated code. */
    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
