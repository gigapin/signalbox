<?php

declare(strict_types=1);

/** Validate an explicit daily silencing interval or its removal for an authorized channel manager. */

namespace App\Http\Requests\Notifications;

use App\Data\Notifications\QuietHours;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** An explicit null disables silencing; omitted, partial and equal-boundary intervals are refused. */
final class UpdateChannelQuietHoursRequest extends FormRequest
{
    /** @return bool Whether the actor may update the channel bound inside the route team. */
    public function authorize(): bool
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel && Gate::allows('update', $channel);
    }

    /** @return array<string, mixed> Complete local times and a runtime-supported IANA timezone, or explicit null. */
    public function rules(): array
    {
        return [
            'quiet_hours' => ['present', 'nullable', 'array:start,end,timezone', 'min:3'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'string', 'regex:'.QuietHours::TIME_PATTERN],
            'quiet_hours.end' => ['required_with:quiet_hours', 'string', 'regex:'.QuietHours::TIME_PATTERN, 'different:quiet_hours.start'],
            'quiet_hours.timezone' => ['required_with:quiet_hours', 'string', Rule::in(QuietHours::timezones())],
        ];
    }

    /** @return QuietHours|null Validated immutable interval, or null to disable silencing. */
    public function quietHours(): ?QuietHours
    {
        if ($this->input('quiet_hours') === null) {
            return null;
        }

        return new QuietHours(
            $this->string('quiet_hours.start')->toString(),
            $this->string('quiet_hours.end')->toString(),
            $this->string('quiet_hours.timezone')->toString(),
        );
    }
}
