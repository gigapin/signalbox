<?php

declare(strict_types=1);

/** Validate the shape of an escalation policy created or changed from the browser. */

namespace App\Http\Requests\Notifications;

use App\Actions\Notifications\ManageEscalationPolicies;
use App\Models\EscalationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Bounds come from `notifications.*`; ordering, channel ownership and name uniqueness are domain rules that
 * ManageEscalationPolicies applies under the Team lock.
 */
final class EscalationPolicyRequest extends FormRequest
{
    /** @return bool Whether the actor may create policies, or change the route policy when one is bound. */
    public function authorize(): bool
    {
        $policy = $this->route('policy');

        if ($policy === null) {
            return Gate::allows('create', EscalationPolicy::class);
        }

        return $policy instanceof EscalationPolicy && Gate::allows('update', $policy);
    }

    /** @return array<string, mixed> Label, default flag and a bounded list of steps with integer channel identities. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ManageEscalationPolicies::NAME_MAX_LENGTH],
            'is_default' => ['required', 'boolean'],
            'steps' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('notifications.steps_per_policy')],
            'steps.*' => ['required', 'array'],
            'steps.*.after_minutes' => ['required', 'integer', 'min:0', 'max:'.(int) config('notifications.max_step_delay_minutes')],
            'steps.*.channel_ids' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('notifications.channels_per_step')],
            // No `distinct`: with nested wildcards it compares across every step; the action removes duplicates per step.
            'steps.*.channel_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> Readable messages for nested fields. */
    public function attributes(): array
    {
        return [
            'steps.*.after_minutes' => 'step delay',
            'steps.*.channel_ids' => 'step channels',
            'steps.*.channel_ids.*' => 'channel',
        ];
    }

    /** @return list<array{after_minutes: int, channel_ids: list<int>}> Validated steps as integers. */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->validated('steps') as $step) {
            $steps[] = [
                'after_minutes' => (int) $step['after_minutes'],
                'channel_ids' => array_values(array_map(intval(...), $step['channel_ids'])),
            ];
        }

        return $steps;
    }
}
