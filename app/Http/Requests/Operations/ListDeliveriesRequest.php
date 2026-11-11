<?php

declare(strict_types=1);

/** Authorize deliveries reads and tolerate invalid bookmark controls. */

namespace App\Http\Requests\Operations;

use App\Data\Operations\OperationalListFilter;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** The route establishes tenancy before this current-membership policy runs. */
final class ListDeliveriesRequest extends FormRequest
{
    /** @var list<string> Supported domain states, also offered by the page. */
    public const array STATES = ['pending', 'sent', 'failed', 'suppressed'];

    /** @return bool Whether the actor still belongs to this team. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', NotificationChannel::class);
    }

    /** @return array<string, mixed> GET controls are parsed without validation redirects. */
    public function rules(): array
    {
        return [];
    }

    /** @return OperationalListFilter Accepted controls and rejected field names. */
    public function filter(): OperationalListFilter
    {
        return OperationalListFilter::fromInput($this->query->all(), self::STATES);
    }
}
