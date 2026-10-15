<?php

/**
 * Connect Laravel web responses to the Inertia 2 client application.
 */

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/** Share the public application identity without tenant or credential data. */
final class HandleInertiaRequests extends Middleware
{
    /** @var string Blade view used for the initial HTML response. */
    protected $rootView = 'app';

    /**
     * Combine standard protocol props with the public product name.
     *
     * @param Request $request Incoming web request used by Inertia's base middleware.
     * @return array<string, mixed> Shared props serialized for the current page.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
        ];
    }
}
