<?php

declare(strict_types=1);

/** Validate the shape of a status page created or changed from the browser. */

namespace App\Http\Requests\StatusPages;

use App\Actions\StatusPages\ManageStatusPages;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Models\StatusPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Monitor ownership, uniqueness and the plan limit are domain rules that ManageStatusPages applies under the Team lock. */
final class StatusPageRequest extends FormRequest
{
    /** @return bool Whether the actor may create pages, or change the route page when one is bound. */
    public function authorize(): bool
    {
        $page = $this->route('statusPage');

        if ($page === null) {
            return Gate::allows('create', StatusPage::class);
        }

        return $page instanceof StatusPage && Gate::allows('update', $page);
    }

    /** @return array<string, mixed> Address, texts, theme, visibility, password change and a bounded list of components. */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'regex:/\A'.StatusPage::SLUG_PATTERN.'\z/'],
            'title' => ['required', 'string', 'max:'.ManageStatusPages::TITLE_MAX_LENGTH],
            'description' => ['nullable', 'string', 'max:'.ManageStatusPages::DESCRIPTION_MAX_LENGTH],
            'theme_mode' => ['required', Rule::enum(StatusPageThemeMode::class)],
            'theme_accent' => ['required', Rule::enum(StatusPageAccent::class)],
            'is_public' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:'.ManageStatusPages::PASSWORD_MIN_LENGTH, 'max:'.ManageStatusPages::PASSWORD_MAX_BYTES],
            'remove_password' => ['sometimes', 'boolean'],
            'components' => ['present', 'array', 'list', 'max:'.ManageStatusPages::COMPONENTS_PER_PAGE],
            'components.*' => ['required', 'array'],
            'components.*.name' => ['required', 'string', 'max:'.ManageStatusPages::COMPONENT_NAME_MAX_LENGTH],
            'components.*.monitor_ids' => ['required', 'array', 'list', 'min:1', 'max:'.ManageStatusPages::MONITORS_PER_COMPONENT],
            'components.*.monitor_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> Readable messages for the address and nested fields. */
    public function messages(): array
    {
        return [
            'slug.regex' => 'An address must be 3 to 63 lowercase letters, digits or inner hyphens.',
        ];
    }

    /** @return array<string, string> Readable names for nested fields. */
    public function attributes(): array
    {
        return [
            'slug' => 'address',
            'components.*.name' => 'component name',
            'components.*.monitor_ids' => 'component monitors',
            'components.*.monitor_ids.*' => 'monitor',
        ];
    }

    /** @return StatusPageInput Validated values for the owning action. */
    public function pageInput(): StatusPageInput
    {
        $validated = $this->validated();
        $components = [];

        foreach ($validated['components'] as $component) {
            $components[] = [
                'name' => (string) $component['name'],
                'monitor_ids' => array_values(array_map(intval(...), $component['monitor_ids'])),
            ];
        }

        return new StatusPageInput(
            (string) $validated['slug'],
            (string) $validated['title'],
            isset($validated['description']) ? (string) $validated['description'] : null,
            new StatusPageTheme(
                StatusPageThemeMode::from((string) $validated['theme_mode']),
                StatusPageAccent::from((string) $validated['theme_accent']),
            ),
            (bool) $validated['is_public'],
            isset($validated['password']) ? (string) $validated['password'] : null,
            (bool) ($validated['remove_password'] ?? false),
            $components,
        );
    }
}
