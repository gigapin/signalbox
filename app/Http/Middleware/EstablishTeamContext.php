<?php

/** Establish an authorized route team before tenant model binding takes place. */

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\TeamAccess;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The explicit route identity takes precedence over the browser's saved preference. */
final class EstablishTeamContext
{
    /**
     * @param TeamAccess $access Fresh membership resolver.
     * @param TenantContext $context Request-scoped tenant unit of work.
     */
    public function __construct(private readonly TeamAccess $access, private readonly TenantContext $context)
    {
    }

    /**
     * @param Request $request Authenticated request with a numeric, unbound team route parameter.
     * @param Closure(Request): Response $next Remaining binding, authorization and controller pipeline.
     * @return Response Response generated within the authorized context.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (!$actor instanceof User) {
            throw new AuthenticationException();
        }

        $team = $request->route('team');
        abort_unless(is_string($team) && ctype_digit($team) && (int) $team > 0, 404);
        $teamId = (int) $team;
        $this->access->membership($actor, $teamId);

        return $this->context->run($teamId, fn (): Response => $next($request));
    }
}
