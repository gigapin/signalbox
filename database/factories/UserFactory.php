<?php

/**
 * Generate synthetic user identities for automated tests and local development.
 */

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Produce users without creating production credentials or external side effects.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @var string|null $password Cached hash for the non-production factory password. */
    protected static ?string $password = null;

    /**
     * Build the default verified synthetic user attributes.
     *
     * @return array<string, mixed> Values consumed by the user model factory.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Produce an identity that still requires email verification.
     */
    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
