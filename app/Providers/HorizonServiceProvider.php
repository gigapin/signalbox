<?php

/** Protect the global queue dashboard independently of tenant permissions and environment. */

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/** Require an authenticated, verified platform operator even in local development. */
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

    /** Define a default-deny operator gate; team ownership grants no global visibility. */
    protected function gate(): void
    {
        Gate::define(
            'viewHorizon',
            /** @param User|null $user Authenticated operator, or null for unauthenticated requests. */
            fn (?User $user): bool => $user !== null
                && $user->email_verified_at !== null
                && in_array($user->email, config('horizon.allowed_emails', []), true),
        );
    }
}
