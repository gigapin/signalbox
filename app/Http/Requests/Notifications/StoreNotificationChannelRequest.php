<?php

declare(strict_types=1);

/** Validate the shape of a new notification channel submitted from the browser. */

namespace App\Http\Requests\Notifications;

use App\Actions\Notifications\ManageNotificationChannels;
use App\Enums\Notifications\ChannelType;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Only the outer shape is checked here; ChannelConfigurationParser applies the per-type rules for every
 * entrypoint. Configuration values are never flashed back (`config` is in the global dontFlash list of bootstrap/app.php), so secrets do not reach the session.
 */
final class StoreNotificationChannelRequest extends FormRequest
{
    /** @return bool Whether the actor may create channels in the route team. */
    public function authorize(): bool
    {
        return Gate::allows('create', NotificationChannel::class);
    }

    /** @return array<string, mixed> Known type, bounded label and a flat object of bounded strings. */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(ChannelType::class)],
            'name' => ['required', 'string', 'max:'.ManageNotificationChannels::NAME_MAX_LENGTH],
            'config' => ['required', 'array'],
            'config.*' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @return ChannelType Validated channel family. */
    public function type(): ChannelType
    {
        return ChannelType::from($this->string('type')->toString());
    }

    /** @return array<array-key, mixed> Submitted configuration fields for the parser. */
    public function configuration(): array
    {
        $value = $this->input('config');

        return is_array($value) ? $value : [];
    }
}
