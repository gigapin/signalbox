<?php

declare(strict_types=1);

/** Apply public indexing only after a successful passwordless page read explicitly authorizes it. */

namespace App\Http\Middleware;

use App\Data\StatusPages\StatusPageMetadata;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Password pages, mutations and mailbox capabilities default to noindex, including partial Inertia responses. */
final class StatusPageIndexing
{
    /**
     * @param Request $request Public status route, possibly carrying an explicit page metadata decision.
     * @param Closure(Request): Response $next Remaining validation/controller pipeline.
     * @return Response Crawler policy and canonical Link only for a successful indexable read.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $metadata = $request->attributes->get('status_page_metadata');
        $indexable = $metadata instanceof StatusPageMetadata && $metadata->canonicalUrl !== null
            && ($request->isMethod('GET') || $request->isMethod('HEAD')) && $response->isSuccessful();

        $response->headers->set('X-Robots-Tag', $indexable ? StatusPageMetadata::PUBLIC_ROBOTS : StatusPageMetadata::PRIVATE_ROBOTS);

        if ($indexable) {
            $response->headers->set('Link', '<'.$metadata->canonicalUrl.'>; rel="canonical"');
        }

        return $response;
    }
}
