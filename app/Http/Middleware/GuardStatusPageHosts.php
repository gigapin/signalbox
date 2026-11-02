<?php

declare(strict_types=1);

/** Reject unknown hosts before sessions and restrict verified custom origins to their own public page. */

namespace App\Http\Middleware;

use App\Services\StatusPages\StatusDomainNames;
use App\Services\StatusPages\StatusDomainRegistry;
use App\Services\StatusPages\StatusDomainSessions;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request as BaseRequest;
use Symfony\Component\HttpFoundation\Response;

/** Raw Host selects the origin; forwarded host headers never select another tenant or enable account routes. */
final readonly class GuardStatusPageHosts
{
    /**
     * @param StatusDomainNames $names Primary-origin classification and canonical customer names.
     * @param StatusDomainRegistry $registry Live verified associations, without authorization caching.
     * @param StatusDomainSessions $sessions Isolated public session and cookie scope.
     */
    public function __construct(private StatusDomainNames $names, private StatusDomainRegistry $registry, private StatusDomainSessions $sessions)
    {
    }

    /**
     * @param Request $request Incoming request after explicit proxy handling, before route/session handling.
     * @param Closure(Request): Response $next Remaining pipeline.
     * @return Response Primary app response or the single custom-host page/form response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getPathInfo() === '/internal/status-domains/allow') {
            abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);
            abort_unless($this->names->allowsTlsPeer($request->server('REMOTE_ADDR')), 403);

            return $next($request);
        }

        try {
            $raw = (string) $request->headers->get('host');
            abort_if($raw === '', 404);
            $host = BaseRequest::create('/', server: ['HTTP_HOST' => $raw])->getHost();
        } catch (SuspiciousOperationException) {
            abort(404);
        }

        if ($this->names->isPrimary($host)) {
            return $next($request);
        }

        try {
            abort_unless($this->names->normalize($host) === $host, 404);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 404);
        $page = $this->registry->publicPage($host);
        abort_if($page === null, 404);
        $path = $request->getPathInfo();
        $publicPath = '/status/'.$page->slug;
        $read = ($request->isMethod('GET') || $request->isMethod('HEAD')) && in_array($path, ['/', $publicPath], true);
        $write = $request->isMethod('POST') && in_array($path, [$publicPath.'/unlock', $publicPath.'/subscribe'], true);
        abort_unless($read || $write, 404);
        $request->attributes->set('status_domain_page', $page->id);
        $request->attributes->set('status_domain_slug', $page->slug);

        $response = $this->sessions->run($request, $page, $next);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
