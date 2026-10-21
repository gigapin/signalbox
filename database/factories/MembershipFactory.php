<?php

/** Generate explicit membership fixtures without altering a user's current selection. */

namespace Database\Factories;

use App\Enums\Identity\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Membership> Produce one additional member on a coherent synthetic team. */
class MembershipFactory extends Factory
{
    /** @return array<string, mixed> Default member pair; the team's primary owner remains separate. */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'user_id' => User::factory(), 'role' => TeamRole::Member];
    }

    /** @return static Additional owner, without replacing the team's designated primary owner. */
    public function owner(): static
    {
        return $this->state(['role' => TeamRole::Owner]);
    }

    /** @return static Administrative membership fixture. */
    public function admin(): static
    {
        return $this->state(['role' => TeamRole::Admin]);
    }

    /** @return static Ordinary contributing membership fixture. */
    public function member(): static
    {
        return $this->state(['role' => TeamRole::Member]);
    }

    /** @return static Read-only role fixture, without implementing its policy. */
    public function viewer(): static
    {
        return $this->state(['role' => TeamRole::Viewer]);
    }
}
