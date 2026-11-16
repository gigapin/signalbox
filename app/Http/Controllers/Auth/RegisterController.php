<?php

/** Acknowledge public registration uniformly without authenticating a requester-selected identity. */

namespace App\Http\Controllers\Auth;

use App\Actions\Identity\RequestPublicRegistration;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/** Browser, Inertia and JSON outcomes disclose neither account existence nor notification delivery. */
final class RegisterController
{
    /** @var string Same public acknowledgement for new and existing accounts. */
    public const string ACKNOWLEDGEMENT = 'Check your email for the next steps. You can also sign in or reset your password.';

    /**
     * @param RegisterRequest $request Validated public registration form.
     * @param RequestPublicRegistration $register Persistence/conflict handling without a login side effect.
     * @return RedirectResponse|JsonResponse Uniform anonymous confirmation for valid input.
     */
    public function __invoke(RegisterRequest $request, RequestPublicRegistration $register): RedirectResponse|JsonResponse
    {
        $register(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('team_name')->toString(),
        );

        return $request->wantsJson()
            ? response()->json(['message' => self::ACKNOWLEDGEMENT])
            : redirect()->route('register.confirmed')->with('status', self::ACKNOWLEDGEMENT);
    }
}
