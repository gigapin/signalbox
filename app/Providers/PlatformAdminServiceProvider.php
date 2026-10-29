<?php

declare(strict_types=1);

/** Register the platform operator gate used by the admin panel and Horizon. */

namespace App\Providers;

use App\Models\User;
use App\Services\Admin\PlatformAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Default-deny: guests, unverified users and every tenant role are refused unless flagged as operators. */
final class PlatformAdminServiceProvider extends ServiceProvider
{
    /** Define the gate on top of PlatformAccess so every surface shares one rule. */
    public function boot(): void
    {
        Gate::define(
            PlatformAccess::GATE,
            /** @param User|null $user Authenticated identity, or null for guests. */
            fn (?User $user): bool => $this->app->make(PlatformAccess::class)->allows($user),
        );
    }
}
