<?php

/**
 * Register scoped tenant identity and enforce the Stripe test-only bootstrap boundary.
 */

namespace App\Providers;

use App\Actions\EnsureStripeTestCredentials;
use App\Contracts\Monitoring\BatchCheckWorkConsumer;
use App\Contracts\Monitoring\CheckClock;
use App\Contracts\Monitoring\CheckWorkConsumer;
use App\Contracts\Monitoring\CheckWorkPublisher;
use App\Contracts\Monitoring\DnsTransport;
use App\Contracts\Monitoring\ResultObserver;
use App\Contracts\Monitoring\TlsTransport;
use App\Contracts\Notifications\ChannelVerificationSender;
use App\Contracts\Notifications\NotificationWorkPublisher;
use App\Contracts\Security\HostResolver;
use App\Events\Incidents\IncidentNotificationRequested;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Events\Monitoring\CheckCompleted;
use App\Services\Broadcasting\PublishTeamUpdates;
use App\Services\Incidents\IncidentDetection;
use App\Services\Monitoring\Checks\MonotonicCheckClock;
use App\Services\Monitoring\Checks\ReactDnsTransport;
use App\Services\Monitoring\Checks\ReactTlsTransport;
use App\Services\Monitoring\Checks\RunMonitorChecks;
use App\Services\Monitoring\Scheduling\RedisCheckWorkPublisher;
use App\Services\Notifications\AdapterChannelVerificationSender;
use App\Services\Notifications\PrepareIncidentNotifications;
use App\Services\Notifications\PublishNotificationDeliveries;
use App\Services\Notifications\RedisNotificationWorkPublisher;
use App\Services\Security\SystemHostResolver;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/** Apply application-wide service boundaries before HTTP requests and queue workers run. */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register tenant identity, the execution publisher, monotonic check clock, outbound DNS resolver and the
     * verification and notification publishers, and disable billing entry points until M9.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext());
        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(CheckWorkPublisher::class, RedisCheckWorkPublisher::class);
        $this->app->bind(BatchCheckWorkConsumer::class, RunMonitorChecks::class);
        $this->app->bind(CheckWorkConsumer::class, RunMonitorChecks::class);
        $this->app->bind(CheckClock::class, MonotonicCheckClock::class);
        $this->app->bind(ResultObserver::class, IncidentDetection::class);
        $this->app->bind(DnsTransport::class, ReactDnsTransport::class);
        $this->app->bind(TlsTransport::class, ReactTlsTransport::class);
        $this->app->bind(ChannelVerificationSender::class, AdapterChannelVerificationSender::class);
        $this->app->bind(NotificationWorkPublisher::class, RedisNotificationWorkPublisher::class);
    }

    /**
     * Enforce test-only Stripe credentials before application requests or workers can run.
     *
     * @param EnsureStripeTestCredentials $ensureStripeTestCredentials Test credential validation action.
     * @param Dispatcher $events Explicit listener registration, without convention-based duplicate discovery.
     * @throws \LogicException When Stripe credentials outside the supported test-mode prefixes are configured.
     */
    public function boot(EnsureStripeTestCredentials $ensureStripeTestCredentials, Dispatcher $events): void
    {
        $ensureStripeTestCredentials((string) config('cashier.key'), (string) config('cashier.secret'));
        $events->listen(IncidentNotificationRequested::class, [PrepareIncidentNotifications::class, 'record']);

        foreach ([IncidentOpened::class, IncidentReopened::class, IncidentResolved::class] as $event) {
            $events->listen($event, [PublishNotificationDeliveries::class, 'publish']);
            $events->listen($event, [PublishTeamUpdates::class, 'publish']);
        }

        $events->listen(CheckCompleted::class, [PublishTeamUpdates::class, 'publish']);
    }
}
