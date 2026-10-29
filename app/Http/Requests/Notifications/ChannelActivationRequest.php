<?php

declare(strict_types=1);

/** Validate a request to start or stop deliveries to a channel. */

namespace App\Http\Requests\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Activating an unverified channel is refused by ManageNotificationChannels under lock. */
final class ChannelActivationRequest extends FormRequest
{
    /** @return bool Whether the actor may manage the route channel. */
    public function authorize(): bool
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel && Gate::allows('update', $channel);
    }

    /** @return array<string, mixed> Required boolean flag. */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }

    /** @return bool Requested state. */
    public function active(): bool
    {
        return $this->boolean('active');
    }
}
