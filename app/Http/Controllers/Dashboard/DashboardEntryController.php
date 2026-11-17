<?php

declare(strict_types=1);

/** Open an authorized team dashboard or render team selection inside the dashboard workspace. */

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Identity\DashboardDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Keep the team-neutral landing page free of tenant domain data. */
final class DashboardEntryController extends Controller
{
    /**
     * @param Request $request Verified account request.
     * @param DashboardDestination $destination Fresh authorized landing rule.
     * @return RedirectResponse|Response Team dashboard or a membership chooser.
     */
    public function __invoke(Request $request, DashboardDestination $destination): RedirectResponse|Response
    {
        $target = $destination->forUser($this->authenticatedUser($request));

        return $target === '/dashboard' ? Inertia::render('Dashboard/Index') : redirect($target);
    }
}
