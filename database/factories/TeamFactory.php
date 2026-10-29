<?php

/** Produce synthetic teams with coherent primary-owner memberships. */

namespace Database\Factories;

use App\Enums\Billing\Plan;
use App\Enums\Identity\TeamRole;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Team> Build team fixtures independently of production creation orchestration. */
class TeamFactory extends Factory
{
    /** @return array<string, mixed> Identity and initial trial attributes for one synthetic team. */
    public function definition(): array
    {
        /** @var CarbonImmutable $startedAt Frozen-clock compatible UTC reference for all initial timestamps. */
        $startedAt = CarbonImmutable::now('UTC');

        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'owner_id' => User::factory(),
            'plan' => Plan::Free,
            'timezone' => 'UTC',
            'trial_started_at' => $startedAt,
            'trial_ends_at' => $startedAt->addDays(14),
            'created_at' => $startedAt,
        ];
    }

    /** @return static Factory creating an owner membership only after team persistence. */
    public function configure(): static
    {
        return $this->afterCreating(/** @param Team $team Synthetic aggregate requiring its primary owner membership. */ function (Team $team): void {
            $team->users()->syncWithoutDetaching([$team->owner_id => ['role' => TeamRole::Owner]]);
        });
    }

    /** @return static Explicit Free base-plan fixture without changing trial history. */
    public function free(): static
    {
        return $this->state(['plan' => Plan::Free]);
    }

    /** @return static Explicit Pro base-plan fixture, not a paid subscription simulation. */
    public function pro(): static
    {
        return $this->state(['plan' => Plan::Pro]);
    }

    /** @return static Team whose single initial trial already ended, so a Free base plan is effective. */
    public function trialEnded(): static
    {
        return $this->state(fn (): array => [
            'trial_started_at' => CarbonImmutable::now('UTC')->subDays(30),
            'trial_ends_at' => CarbonImmutable::now('UTC')->subDays(16),
        ]);
    }

    /** @return static Explicit Business base-plan fixture without external billing effects. */
    public function business(): static
    {
        return $this->state(['plan' => Plan::Business]);
    }
}
