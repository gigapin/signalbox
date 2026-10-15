<?php

/**
 * Render the public application entry point through the Inertia protocol.
 */

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Serve the initial application page without exposing operational data. */
final class HomeController extends Controller
{
    /**
     * Return the React home page with only the middleware's public shared props.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Home');
    }
}
