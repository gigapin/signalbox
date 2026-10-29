<?php

/** Prevent storage of authenticated pages and authentication responses. */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apply explicit cache policy to the complete authentication route surface. */
final class NoStoreAuthentication
{
    /**
     * @param Request $request Authentication or account request.
     * @param Closure(Request): Response $next Remaining middleware and controller.
     * @return Response Private, non-cacheable result.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        // Bearer invitation URLs must not leak through browser referrer headers.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
