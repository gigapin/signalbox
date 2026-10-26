<?php

declare(strict_types=1);

/** Bound anonymous heartbeat discovery traffic before any credential or tenant database lookup. */

namespace App\Http\Middleware;

use App\Services\Monitoring\HeartbeatLimits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Refusals return a generic response so the enclosing no-store middleware also covers throttling errors. */
final readonly class LimitHeartbeatIngress
{
    /** @param HeartbeatLimits $limits Mandatory per-IP limit, independent of authentication status. */
    public function __construct(private HeartbeatLimits $limits)
    {
    }

    /**
     * @param Request $request Public heartbeat request without session authentication.
     * @param Closure(Request): Response $next Dedicated receiver, reached only below the IP limit.
     * @return Response Ordinary receiver result or a non-identifying 429 with retry duration.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'heartbeat:ip:'.hash('sha256', $request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, $this->limits->get('requests_per_ip_per_minute'))) {
            return response()->json(['message' => 'Too many heartbeat requests.'], 429, ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
