<?php

declare(strict_types=1);

/** Authorize and bound a custom hostname change before invoking the network-free claim action. */

namespace App\Http\Requests\StatusPages;

use App\Models\StatusPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Normalization and reserved-name rules remain in the domain action, including direct callers. */
final class StatusDomainRequest extends FormRequest
{
    /** @return bool Whether the actor may manage the tenant-bound route page. */
    public function authorize(): bool
    {
        $page = $this->route('statusPage');

        return $page instanceof StatusPage && Gate::allows('update', $page);
    }

    /** @return array<string, mixed> Explicit nullable hostname; null removes its association. */
    public function rules(): array
    {
        return ['domain' => ['present', 'nullable', 'string', 'max:1024']];
    }
}
