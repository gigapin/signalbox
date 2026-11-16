<?php

declare(strict_types=1);

/** Validate current URL signatures or a narrowly retained non-expiring unsubscribe capability. */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/** The retained hash authenticates the complete path/query; consent/generation checks still run downstream. */
final class ValidateSubscriptionSignature
{
    /**
     * @param Request $request Public consent request, with no identity inferred from an unsigned path.
     * @param Closure(Request): Response $next Existing controller enforces tenant, generation and explicit POST.
     * @return Response Signature-authorized response.
     * @throws InvalidSignatureException Signature is invalid and no exact unsubscribe grant exists.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (URL::hasValidSignature($request, absolute: false)) {
            return $next($request);
        }

        $path = '/'.$request->path().'?'.(string) $request->server->get('QUERY_STRING');
        if ($request->route('action') === 'unsubscribe'
            && DB::table('status_subscription_link_grants')->where('link_hash', hash('sha256', $path))
                ->where('team_id', $request->route('teamId'))->where('status_page_id', $request->route('pageId'))
                ->where('subscriber_id', $request->route('subscriberId'))->where('generation', $request->route('generation'))->exists()) {
            return $next($request);
        }

        throw new InvalidSignatureException();
    }
}
