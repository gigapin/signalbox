<?php

/** Adapt capability-backed invited registration to the existing verified-account lifecycle. */

namespace App\Http\Controllers\Auth;

use App\Actions\Identity\RegisterInvitedUser;
use App\Http\Requests\Teams\InvitedRegistrationRequest;
use App\Http\Support\InvitationSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Register no personal team; joining still needs verified explicit acceptance. */
final class InvitedRegistrationController
{
    /** @param InvitationSession $context Validated continuation state from the original bearer link. */
    public function __construct(private readonly InvitationSession $context)
    {
    }

    /**
     * @param Request $request Guest requesting invited registration.
     * @return Response|RedirectResponse Registration fields or invalid invitation review.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $invitation = $this->context->resolve($request);

        if ($invitation === null || !$invitation->isPending()) {
            return redirect()->route('invitations.review');
        }

        return Inertia::render('Teams/Register', ['email' => $invitation->email]);
    }

    /**
     * @param InvitedRegistrationRequest $request Validated account fields, never ownership or role.
     * @param RegisterInvitedUser $register Capability-aware transactional registration.
     * @return RedirectResponse Existing mandatory verification flow.
     */
    public function store(InvitedRegistrationRequest $request, RegisterInvitedUser $register): RedirectResponse
    {
        $invitation = $this->context->resolve($request);

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'invitation' => 'A valid invitation is required. Request a new link.',
            ]);
        }

        try {
            $user = $register(
                $request->string('name')->toString(),
                $request->string('email')->toString(),
                $request->string('password')->toString(),
                $invitation->id,
                $invitation->token_hash,
            );
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'users_email')) {
                throw ValidationException::withMessages([
                    'email' => 'The email has already been taken.',
                ]);
            }

            throw $exception;
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->password);
        $request->session()->put('url.intended', route('invitations.review'));

        return redirect()->route('verification.notice');
    }
}
