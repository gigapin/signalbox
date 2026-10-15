<?php

/**
 * Disable premature billing endpoints and enforce the Stripe test-only bootstrap boundary.
 */

namespace App\Providers;

use App\Actions\EnsureStripeTestCredentials;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/** Apply application-wide service boundaries before HTTP requests and queue workers run. */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Disable package billing entry points until team billing and webhook idempotency exist.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();
    }

    /**
     * Enforce test-only Stripe credentials before application requests or workers can run.
     *
     * @param EnsureStripeTestCredentials $ensureStripeTestCredentials Test credential validation action.
     * @throws \LogicException When Stripe credentials outside the supported test-mode prefixes are configured.
     */
    public function boot(EnsureStripeTestCredentials $ensureStripeTestCredentials): void
    {
        $ensureStripeTestCredentials((string) config('cashier.key'), (string) config('cashier.secret'));
    }
}
