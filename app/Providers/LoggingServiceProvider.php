<?php

declare(strict_types=1);

/** Install fallback-safe logging before application providers can emit diagnostics. */

namespace App\Providers;

use App\Logging\RedactingLogManager;
use Illuminate\Support\ServiceProvider;

/** Replace only the manager construction; channel configuration and public contracts stay unchanged. */
final class LoggingServiceProvider extends ServiceProvider
{
    /** Bind the manager lazily so logging configuration is available when first resolved. */
    public function register(): void
    {
        $this->app->singleton('log', fn (): RedactingLogManager => new RedactingLogManager($this->app));
    }
}
