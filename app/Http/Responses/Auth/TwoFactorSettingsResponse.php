<?php

declare(strict_types=1);

/** Keep authenticator management redirects on the security page, outside session back-navigation. */

namespace App\Http\Responses\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse;
use Laravel\Fortify\Contracts\TwoFactorEnabledResponse;
use Symfony\Component\HttpFoundation\Response;

/** Adapt Fortify's identical settings response contracts while preserving each operation's status. */
final class TwoFactorSettingsResponse implements TwoFactorEnabledResponse, TwoFactorConfirmedResponse, TwoFactorDisabledResponse, RecoveryCodesGeneratedResponse
{
    /** @param string $status Fortify's non-secret acknowledgement for the completed operation. */
    public function __construct(private readonly string $status)
    {
    }

    /**
     * @param Request $request Completed settings mutation with the existing authorization checks applied.
     * @return Response Original JSON acknowledgement or the explicit security page with its status flash.
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        return redirect()->route('account.security')->with('status', $this->status);
    }
}
