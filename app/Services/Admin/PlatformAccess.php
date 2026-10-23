<?php

declare(strict_types=1);

/** Decide whether an identity may act as a platform operator. */

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;

/**
 * Platform operators are separate from team roles: owning a team grants nothing here. The flag is read
 * afresh from the database on every check, so a revocation takes effect on the next request even for an
 * already authenticated session. In production a confirmed second factor is also required; the rule is
 * derived from the environment and has no switch that could disable it there.
 */
final readonly class PlatformAccess
{
    /** @var string GATE Gate name shared by the admin panel and Horizon. */
    public const string GATE = 'accessPlatformAdmin';

    /** @param Application $app Application whose environment decides the second-factor rule. */
    public function __construct(private Application $app)
    {
    }

    /**
     * @param User|null $user Authenticated identity, or null for guests.
     * @return bool Whether the user is a verified operator according to the persisted flag.
     */
    public function isOperator(?User $user): bool
    {
        if ($user === null || $user->email_verified_at === null) {
            return false;
        }

        return User::query()->whereKey($user->id)->value('is_platform_admin') === true;
    }

    /**
     * @param User $user Operator being checked.
     * @return bool Whether the second-factor rule of this environment is met.
     */
    public function meetsSecondFactor(User $user): bool
    {
        return !$this->app->isProduction() || $user->two_factor_confirmed_at !== null;
    }

    /**
     * @param User|null $user Authenticated identity, or null for guests.
     * @return bool Whether the user may use operator surfaces right now.
     */
    public function allows(?User $user): bool
    {
        return $user !== null && $this->isOperator($user) && $this->meetsSecondFactor($user);
    }
}
