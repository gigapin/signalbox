<?php

declare(strict_types=1);

/** Validate the escalation policy chosen for a monitor. */

namespace App\Http\Requests\Notifications;

use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Ownership of the policy is checked by AssignEscalationPolicy under the Team lock. */
final class AssignEscalationPolicyRequest extends FormRequest
{
    /** @return bool Whether the actor may configure the route monitor. */
    public function authorize(): bool
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor && Gate::allows('update', $monitor);
    }

    /** @return array<string, mixed> Present policy identity, or null to follow the team default. */
    public function rules(): array
    {
        return ['escalation_policy_id' => ['present', 'nullable', 'integer', 'min:1']];
    }

    /** @return int|null Chosen policy, or null for the team default. */
    public function policyId(): ?int
    {
        $value = $this->input('escalation_policy_id');

        return $value === null ? null : (int) $value;
    }
}
