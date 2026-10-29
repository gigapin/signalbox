<?php

declare(strict_types=1);

/** Validate an operator's plan override submission. */

namespace App\Http\Requests\Admin;

use App\Actions\Admin\ManagePlanOverrides;
use App\Enums\Billing\Plan;
use App\Enums\Billing\PlanOverrideKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The form sends an inclusive "valid through" UTC date; the stored expiry is the exclusive start of the
 * following day. ManagePlanOverrides repeats the coherence checks under the Team lock.
 */
final class SavePlanOverrideRequest extends FormRequest
{
    /** @return bool Always true; EnsurePlatformAdmin and the action's gate decide authorization. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> Kind, plan for granted plans only, optional future date and a reason. */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(PlanOverrideKind::class)],
            'plan' => [
                'nullable',
                'required_if:kind,'.PlanOverrideKind::Comped->value,
                'prohibited_if:kind,'.PlanOverrideKind::Unlimited->value,
                Rule::in([Plan::Pro->value, Plan::Business->value]),
            ],
            'valid_through' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:'.ManagePlanOverrides::REASON_MAX_LENGTH],
        ];
    }

    /** @return PlanOverrideKind Validated kind. */
    public function kind(): PlanOverrideKind
    {
        return PlanOverrideKind::from($this->string('kind')->toString());
    }

    /** @return Plan|null Granted plan for comped overrides. */
    public function plan(): ?Plan
    {
        return $this->filled('plan') ? Plan::from($this->string('plan')->toString()) : null;
    }

    /** @return CarbonImmutable|null Exclusive UTC expiry, the day after the inclusive date, or null when open-ended. */
    public function expiresAt(): ?CarbonImmutable
    {
        if (!$this->filled('valid_through')) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('valid_through')->toString(), 'UTC')?->addDay();
    }

    /** @return string Validated justification. */
    public function reason(): string
    {
        return $this->string('reason')->toString();
    }
}
