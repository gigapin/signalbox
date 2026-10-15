<?php

/**
 * Provide the extension point for application service bindings and boot hooks.
 */

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/** Bootstrap Signalbox services as their owning milestones are implemented. */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application dependencies when domain services are introduced.
     */
    public function register(): void
    {
    }

    /**
     * Initialize application behavior after all service providers are registered.
     */
    public function boot(): void
    {
    }
}
