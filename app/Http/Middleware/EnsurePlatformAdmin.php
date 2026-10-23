<?php

declare(strict_types=1);

/** Admit only platform operators to the admin panel. */

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Admin\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Non-operators receive 404, so the panel's existence is not revealed. An operator who lacks the
 * production second factor is sent to the security page instead of being refused silently.
 */
final readonly class EnsurePlatformAdmin
{
    /** @param PlatformAccess $access Shared operator rule. */
    public function __construct(private PlatformAccess $access)
    {
    }

    /**
     * @param Request $request Authenticated, verified request.
     * @param Closure(Request): Response $next Remaining middleware and controller.
     * @return Response Panel response, or a redirect asking to enable two-factor authentication.
     * @throws NotFoundHttpException When the user is not a platform operator.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User || !$this->access->isOperator($user)) {
            throw new NotFoundHttpException();
        }

        if (!$this->access->meetsSecondFactor($user)) {
            return redirect()->route('account.security')->with('status', 'platform-admin-two-factor-required');
        }

        return $next($request);
    }
}
