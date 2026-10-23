<?php

declare(strict_types=1);

/** Validate the reason given for removing a plan override. */

namespace App\Http\Requests\Admin;

use App\Actions\Admin\ManagePlanOverrides;
use Illuminate\Foundation\Http\FormRequest;

/** Clearing is audited like granting, so it requires the same justification. */
final class ClearPlanOverrideRequest extends FormRequest
{
    /** @return bool Always true; EnsurePlatformAdmin and the action's gate decide authorization. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> A bounded reason. */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:'.ManagePlanOverrides::REASON_MAX_LENGTH]];
    }

    /** @return string Validated justification. */
    public function reason(): string
    {
        return $this->string('reason')->toString();
    }
}
