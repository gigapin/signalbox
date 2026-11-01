<?php

declare(strict_types=1);

/** Project status pages for the team page that manages them. */

namespace App\Http\Presenters\StatusPages;

use App\Actions\StatusPages\ManageStatusPages;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;

/** The password hash never appears: members only learn whether a password exists. */
final readonly class StatusPagePresenter
{
    /**
     * @param StatusPage $page Page with components and their monitors loaded.
     * @return array<string, mixed> Row with editable values and its public path.
     */
    public function page(StatusPage $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'path' => '/status/'.$page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'theme' => $page->theme()->toArray(),
            'isPublic' => $page->is_public,
            'hasPassword' => $page->password_hash !== null,
            'components' => $page->components->map(static fn (StatusPageComponent $component): array => [
                'name' => $component->name,
                'monitors' => $component->monitors
                    ->sortBy('name')
                    ->values()
                    ->map(static fn (Monitor $monitor): array => ['id' => $monitor->id, 'name' => $monitor->name])
                    ->all(),
            ])->all(),
        ];
    }

    /**
     * @param int $pages Maximum pages of the team's effective plan.
     * @param int $used Pages the team holds.
     * @return array<string, int> Form bounds and plan usage.
     */
    public function limits(int $pages, int $used): array
    {
        return [
            'pages' => $pages,
            'used' => $used,
            'titleMaxLength' => ManageStatusPages::TITLE_MAX_LENGTH,
            'descriptionMaxLength' => ManageStatusPages::DESCRIPTION_MAX_LENGTH,
            'componentNameMaxLength' => ManageStatusPages::COMPONENT_NAME_MAX_LENGTH,
            'componentsPerPage' => ManageStatusPages::COMPONENTS_PER_PAGE,
            'monitorsPerComponent' => ManageStatusPages::MONITORS_PER_COMPONENT,
            'passwordMinLength' => ManageStatusPages::PASSWORD_MIN_LENGTH,
        ];
    }

    /** @return array{modes: list<string>, accents: list<string>} Appearance choices offered by the forms. */
    public function themeChoices(): array
    {
        return [
            'modes' => array_map(static fn (StatusPageThemeMode $mode): string => $mode->value, StatusPageThemeMode::cases()),
            'accents' => array_map(static fn (StatusPageAccent $accent): string => $accent->value, StatusPageAccent::cases()),
        ];
    }
}
