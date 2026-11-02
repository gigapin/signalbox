<?php

/**
 * Render the public application entry point through the Inertia protocol.
 */

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Serve the initial application page without exposing operational data. */
final class HomeController extends Controller
{
    /**
     * @param Request $request Origin already admitted by the host guard.
     * @return Response|RedirectResponse Primary home page, or the verified custom page's relative public path.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $slug = $request->attributes->get('status_domain_slug');

        if (is_string($slug)) {
            return redirect('/status/'.$slug);
        }

        return Inertia::render('Home');
    }
}
