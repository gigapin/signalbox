<?php

declare(strict_types=1);

/** Run custom-domain public forms with an independent session store and host-only cookie. */

namespace App\Services\StatusPages;

use App\Models\StatusPage;
use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/** Restore container singletons and configuration after every request, including errors in reused test/worker containers. */
final class StatusDomainSessions
{
    /**
     * @param Request $request Custom-origin request admitted by the live registry.
     * @param StatusPage $page Bound public page and current claim generation.
     * @param Closure(Request): Response $next Remaining global and web middleware.
     * @return Response Public page/form response without primary account/session state.
     */
    public function run(Request $request, StatusPage $page, Closure $next): Response
    {
        $app = app();
        $previous = [
            'manager' => $app->make('session'),
            'store' => $app->make('session.store'),
            'middleware' => $app->make(StartSession::class),
            'resolver' => $request->getUserResolver(),
        ];
        $config = config('session');
        $redirect = $app->make('redirect');
        $auth = $app->make('auth');
        $identityResolver = $auth->userResolver();
        $name = 'signalbox_status_'.substr(hash('sha256', $page->custom_domain.':'.$page->id.':'.$page->domain_challenge), 0, 24);

        config([
            'session.cookie' => $name,
            'session.domain' => null,
            'session.path' => '/',
            'session.secure' => $request->isSecure(),
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
        $manager = new SessionManager($app);
        $store = $manager->driver();
        Session::swap($manager);
        $app->instance('session.store', $store);
        $app->instance(StartSession::class, new StartSession($manager, static fn (): Factory => app(Factory::class)));
        $redirect->setSession($store);
        // The HTTP kernel rebinds Request immediately before routing, so mask the manager's resolver as well.
        $auth->resolveUsersUsing(static fn (): null => null);
        $request->setUserResolver(static fn (): null => null);

        try {
            return $next($request);
        } finally {
            config(['session' => $config]);
            Session::swap($previous['manager']);
            $app->instance('session.store', $previous['store']);
            $app->instance(StartSession::class, $previous['middleware']);
            $redirect->setSession($previous['store']);
            $auth->resolveUsersUsing($identityResolver);
            $request->setUserResolver($previous['resolver']);
        }
    }
}
