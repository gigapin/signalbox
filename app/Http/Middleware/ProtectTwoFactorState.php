<?php

/** Serialize second-factor state transitions and reject stale pending login sessions. */

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Keep recovery-code consumption and all management mutations on the same user row lock. */
final class ProtectTwoFactorState
{
    /**
     * Execute with fresh user state and protect sensitive responses from browser/proxy caching.
     * @param Request $request Authenticated management request or pending guest challenge.
     * @param Closure(Request): Response $next Remaining route middleware and controller.
     * @return Response Controller response, or a login redirect for expired/changed credentials.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(/** Acquire the shared row lock before resolving or changing security state. */ function () use ($request, $next): Response {
            /** @var bool $challenge Whether the route authenticates a pending guest session. */
            $challenge = $request->routeIs('two-factor.login', 'two-factor.login.store');
            /** @var mixed $id Server-owned identity; never taken from a request body or query string. */
            $id = $challenge ? $request->session()->get('login.id') : Auth::id();
            /** @var User|null $user Fresh identity serialized against other 2FA transitions. */
            $user = is_numeric($id) ? User::query()->lockForUpdate()->find((int) $id) : null;
            if ($challenge) {
                /** @var mixed $startedAt Credential verification time from the same session. */
                $startedAt = $request->session()->get('login.started_at');
                /** @var mixed $fingerprint Opaque fingerprint, invalidated by password or secret changes. */
                $fingerprint = $request->session()->get('login.fingerprint');
                if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()
                    || ! is_int($startedAt) || $startedAt > now()->getTimestamp() || now()->getTimestamp() - $startedAt >= 300
                    || ! is_string($fingerprint) || ! hash_equals(hash('sha256', $user->password.'|'.$user->two_factor_secret), $fingerprint)) {
                    $request->session()->forget('login');
                    return redirect()->route('login');
                }
            } else {
                abort_unless($user instanceof User, 401);
                Auth::guard('web')->setUser($user);
                $request->setUserResolver(/** Return only the identity refreshed while holding its security lock. */ static fn (): User => $user);
            }

            /** @var Response $response Fully resolved HTTP response from the next middleware. */
            $response = $next($request);
            if ($challenge && Auth::check()) {
                $request->session()->forget('login');
                // Seed revocation tracking immediately, before the first protected page is visited.
                $request->session()->put('password_hash_web', Auth::user()?->getAuthPassword());
            }
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        });
    }
}
