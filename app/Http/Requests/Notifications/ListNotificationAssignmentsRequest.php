<?php

declare(strict_types=1);

/** Authorize monitor assignment reads and parse a bounded optional monitor identity. */

namespace App\Http\Requests\Notifications;

use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Invalid or foreign resource identities fail closed instead of producing a GET validation redirect. */
final class ListNotificationAssignmentsRequest extends FormRequest
{
    /** @return bool Whether the current actor may inspect this team's monitors. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
    }

    /** @return array<string, mixed> The optional identity is parsed without a redirect loop. */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return int|null A positive monitor ID of at most eighteen digits, or no selection.
     * @throws HttpException When the submitted identity is malformed.
     */
    public function monitorId(): ?int
    {
        $selection = $this->query('monitor_id');
        if ($selection === null) {
            return null;
        }

        abort_unless(is_string($selection) && preg_match('/^[1-9][0-9]{0,17}$/D', $selection) === 1, 404);

        return (int) $selection;
    }
}
