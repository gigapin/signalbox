<?php

declare(strict_types=1);

/** Validate the shape of a notification channel change submitted from the browser. */

namespace App\Http\Requests\Notifications;

use App\Actions\Notifications\ManageNotificationChannels;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** The type cannot change; blank secret fields keep the stored secrets (ChannelConfigurationParser); `config` is never flashed back. */
final class UpdateNotificationChannelRequest extends FormRequest
{
    /** @return bool Whether the actor may manage the route channel. */
    public function authorize(): bool
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel && Gate::allows('update', $channel);
    }

    /** @return array<string, mixed> Bounded label and a flat object of bounded strings. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ManageNotificationChannels::NAME_MAX_LENGTH],
            'config' => ['present', 'array'],
            'config.*' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @return array<array-key, mixed> Submitted configuration fields for the parser. */
    public function configuration(): array
    {
        $value = $this->input('config');

        return is_array($value) ? $value : [];
    }
}
