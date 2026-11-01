<?php

declare(strict_types=1);

/** Build synthetic status pages only within an explicitly established tenant context. */

namespace Database\Factories;

use App\Models\StatusPage;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatusPage> A draft without components or password unless states say otherwise. */
final class StatusPageFactory extends Factory
{
    /** @return array<string, mixed> Draft page with a unique address and the default theme. */
    public function definition(): array
    {
        return [
            'team_id' => app(TenantContext::class)->requireTeamId(),
            'slug' => 'page-'.fake()->unique()->numberBetween(1, 999_999),
            'title' => 'Synthetic status',
            'theme_json' => ['mode' => 'system', 'accent' => 'emerald'],
        ];
    }

    /** @return static Page answering at its public address. */
    public function published(): static
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }
}
