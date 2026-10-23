<?php

/**
 * Register scoped tenant identity and enforce the Stripe test-only bootstrap boundary.
 */

namespace App\Providers;

use App\Actions\EnsureStripeTestCredentials;
use App\Contracts\Monitoring\CheckWorkPublisher;
use App\Contracts\Security\HostResolver;
use App\Services\Monitoring\Scheduling\RedisCheckWorkPublisher;
use App\Services\Security\SystemHostResolver;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/** Apply application-wide service boundaries before HTTP requests and queue workers run. */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register request/job-scoped tenant identity, the execution publisher and the outbound DNS resolver,
     * and disable billing entry points until M9.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext());
        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(CheckWorkPublisher::class, RedisCheckWorkPublisher::class);
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
