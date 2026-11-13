<?php

declare(strict_types=1);

/** Add browser security headers to every response, error pages and custom status domains included. */

namespace App\Http\Middleware;

use App\Services\Security\ContentSecurityPolicy;
use App\Services\StatusPages\StatusDomainNames;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered first in the global stack, so responses rendered from exceptions in inner middleware also pass here.
 * The nonce is created before the request is handled, so Blade tags rendered later carry it.
 *
 * With `APP_DEBUG` on, the policy is sent as `Content-Security-Policy-Report-Only`: the framework's debug page uses
 * inline scripts and styles, and developers still see every violation in the browser console. Startup checks
 * refuse debug outside local development and tests, so deployments always enforce the policy.
 *
 * HSTS is sent only on the primary origin over HTTPS and without `includeSubDomains` or `preload`: custom status
 * domains belong to customers, and committing other subdomains of the primary domain is a deployment decision.
 */
final readonly class SetSecurityHeaders
{
    /** @var string HSTS lifetime of one year, in seconds. */
    public const string STRICT_TRANSPORT_SECURITY = 'max-age=31536000';

    /** @var string Powerful browser features the application never uses, disabled for every frame. */
    public const string PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    /** @var string Default referrer policy; routes that set a stricter one keep it. */
    public const string REFERRER_POLICY = 'strict-origin-when-cross-origin';

    /**
     * @param ContentSecurityPolicy $policy Per-request policy builder.
     * @param Vite $vite Asset tag renderer that receives the nonce.
     * @param StatusDomainNames $names Primary-origin classification for HSTS.
     * @param Repository $config Debug flag selecting enforcement or report-only mode.
     */
    public function __construct(
        private ContentSecurityPolicy $policy,
        private Vite $vite,
        private StatusDomainNames $names,
        private Repository $config,
    ) {
    }

    /**
     * @param Request $request Incoming request, before host routing and sessions.
     * @param Closure(Request): Response $next Remaining global pipeline.
     * @return Response The same response with security headers added.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = $this->vite->useCspNonce();
        Horizon::cspNonce($nonce);

        $response = $next($request);
        $host = $this->host($request);

        $policyHeader = $this->config->get('app.debug') === true
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($policyHeader, $this->policy->for($request, $host, $nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);

        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', self::REFERRER_POLICY);
        }

        if ($host !== null && $request->isSecure() && $this->names->isPrimary($host)) {
            $response->headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }

        return $response;
    }

    /**
     * A malformed Host header has already produced a 404 inside the pipeline; the headers must still be added.
     *
     * @param Request $request Handled request.
     * @return string|null Validated lowercase hostname, or null when the Host header is malformed.
     */
    private function host(Request $request): ?string
    {
        try {
            return $request->getHost();
        } catch (SuspiciousOperationException) {
            return null;
        }
    }
}
