<?php

/**
 * Register application-owned providers independently of package discovery.
 */

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
