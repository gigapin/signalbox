<?php

declare(strict_types=1);

/** Build synthetic escalation policies only within an explicitly established tenant context. */

namespace Database\Factories;

use App\Models\EscalationPolicy;
use App\Models\NotificationChannel;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EscalationPolicy> One immediate step to a new channel of the same team unless steps are given. */
final class EscalationPolicyFactory extends Factory
{
    /** @return array<string, mixed> Non-default policy whose single step notifies a fresh email channel. */
    public function definition(): array
    {
        return [
            'team_id' => app(TenantContext::class)->requireTeamId(),
            'name' => 'Policy '.fake()->unique()->numberBetween(1, 999_999),
            'steps_json' => fn (): array => [['after_minutes' => 0, 'channel_ids' => [NotificationChannel::factory()->create()->id]]],
        ];
    }

    /** @return static Team default policy. */
    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
