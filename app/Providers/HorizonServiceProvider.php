<?php

/** Protect the global queue dashboard independently of tenant permissions and environment. */

namespace App\Providers;

use App\Models\User;
use App\Services\Admin\PlatformAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/** Require a platform operator (PLATFORM-ADMIN.md) even in local development. */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /** Override the package's local-environment bypass for both dashboard and JSON endpoints. */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(
            /** @param Request $request Incoming dashboard or Horizon API request. */
            fn (Request $request): bool => Gate::forUser($request->user())->allows('viewHorizon'),
        );
    }

    /** Delegate to the platform operator gate; team ownership grants no global visibility. */
    protected function gate(): void
    {
        Gate::define(
            'viewHorizon',
            /** @param User|null $user Authenticated operator, or null for unauthenticated requests. */
            fn (?User $user): bool => Gate::forUser($user)->allows(PlatformAccess::GATE),
        );
    }
}
