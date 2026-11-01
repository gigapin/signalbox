<?php

declare(strict_types=1);

/** Resolve the status pages reachable at a public address and project what visitors may see. */

namespace App\Services\StatusPages;

use App\Models\Scopes\TeamScope;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Services\Tenancy\TenantContext;

/**
 * The only code reading a page without an established tenant. Drafts are indistinguishable from addresses that do
 * not exist, and the projection lists fields explicitly: monitor names and every identity stay out of it.
 */
final readonly class PublicStatusPages
{
    /** @param TenantContext $context Explicit tenant boundary for the page's components. */
    public function __construct(private TenantContext $context)
    {
    }

    /**
     * @param string $slug Public address segment.
     * @return StatusPage|null Published page at that address; null for a draft or an unknown address.
     */
    public function find(string $slug): ?StatusPage
    {
        return StatusPage::withoutGlobalScope(TeamScope::class)
            ->where('slug', $slug)
            ->where('is_public', true)
            ->first();
    }

    /**
     * @param StatusPage $page Published page the visitor may read.
     * @return array{title: string, description: string|null, theme: array{mode: string, accent: string}, components: list<array{name: string}>} Visitor projection.
     */
    public function present(StatusPage $page): array
    {
        $names = $this->context->run($page->team_id, static fn (): array => StatusPageComponent::query()
            ->where('status_page_id', $page->id)
            ->orderBy('position')
            ->pluck('name')
            ->all());

        return [
            'title' => $page->title,
            'description' => $page->description,
            'theme' => $page->theme()->toArray(),
            'components' => array_map(static fn (string $name): array => ['name' => $name], array_values($names)),
        ];
    }
}
