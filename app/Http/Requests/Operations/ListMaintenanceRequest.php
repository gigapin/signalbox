<?php

declare(strict_types=1);

/** Authorize maintenance reads and tolerate invalid bookmark controls. */

namespace App\Http\Requests\Operations;

use App\Data\Operations\OperationalListFilter;
use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** The route establishes tenancy before this current-membership policy runs. */
final class ListMaintenanceRequest extends FormRequest
{
    /** @var list<string> Supported domain states, also offered by the page. */
    public const array STATES = ['upcoming', 'ongoing', 'finished'];

    /** @return bool Whether the actor still belongs to this team. */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Monitor::class);
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
