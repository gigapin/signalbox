<?php

/** Initialize first-paint application appearance without overriding public status-page themes. */

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Appearance\UiTheme;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Share a safe request attribute and preserve account appearance in a browser cookie. */
final readonly class ResolveUiTheme
{
    /**
     * @param UiTheme $themes Account and browser theme resolver.
     */
    public function __construct(private UiTheme $themes)
    {
    }

    /**
     * @param Request $request Current web request.
     * @param Closure(Request): Response $next Remaining pipeline.
     * @return Response Rendered response with synchronized browser appearance.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $public = $request->attributes->has('status_domain_page') || $request->is('status/*');
        $request->attributes->set('ui_theme', $public ? null : $this->themes->resolve($request));
        $response = $next($request);

        if (!$public && $request->user() instanceof User && !$request->routeIs('preferences.theme')) {
            $theme = $this->themes->resolve($request);
            if ($request->cookie(UiTheme::COOKIE) !== $theme) {
                $response->headers->setCookie($this->themes->cookie($theme, $request));
            }
        }

        return $response;
    }
}
