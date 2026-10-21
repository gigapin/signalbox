<?php

/** Adapt validated registration into an atomic domain action and a web session. */

namespace App\Http\Controllers\Auth;

use App\Actions\Identity\RegisterUser;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Keep browser login orchestration outside account persistence rules. */
final class RegisterController
{
    /**
     * @param RegisterRequest $request Validated public registration form.
     * @param RegisterUser $register Atomic identity aggregate creator.
     * @return RedirectResponse Mandatory email verification screen.
     */
    public function __invoke(RegisterRequest $request, RegisterUser $register): RedirectResponse
    {
        try {
            /** @var \App\Models\User $user Newly committed identity, never trusted from client-owned role fields. */
            $user = $register($request->string('name')->toString(), $request->string('email')->toString(), $request->string('password')->toString(), $request->string('team_name')->toString());
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent validated registrations still compete on PostgreSQL's email constraint.
            if (str_contains($exception->getMessage(), 'users_email')) {
                throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
            }
            throw $exception;
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->password);

        return redirect()->route('verification.notice');
    }
}
