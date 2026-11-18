<?php

/** Session-owned profile mutations and private avatar delivery. */

namespace App\Http\Controllers;

use App\Http\Requests\Account\UpdateProfileRequest;
use App\Services\Account\AccountProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Orchestrate the authenticated profile surface without tenant input. */
final class AccountProfileController extends Controller
{
    /**
     * @param UpdateProfileRequest $request Validated owner input.
     * @param AccountProfile $profiles Domain coordinator.
     * @return RedirectResponse Confirmed profile and delivery outcome.
     */
    public function update(UpdateProfileRequest $request, AccountProfile $profiles): RedirectResponse
    {
        return redirect('/account')->with('status', $profiles->save($this->authenticatedUser($request), $request->validated(), $request->file('avatar')));
    }
    /**
     * @param Request $request Current session.
     * @return StreamedResponse Private owner image, never an arbitrary file path.
     */
    public function avatar(Request $request): StreamedResponse
    {
        $path = $this->authenticatedUser($request)->refresh()->avatar_path;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);
        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'no-store, private','X-Content-Type-Options' => 'nosniff']);
    }
    /**
     * @param Request $request Current owner.
     * @param AccountProfile $profiles Email coordinator.
     * @return RedirectResponse Delivery feedback.
     */
    public function resend(Request $request, AccountProfile $profiles): RedirectResponse
    {
        return redirect('/account')->with('status', $profiles->resend($this->authenticatedUser($request)));
    }
    /**
     * @param Request $request Current owner.
     * @param AccountProfile $profiles Email coordinator.
     * @return RedirectResponse Pending change cancelled.
     */
    public function cancel(Request $request, AccountProfile $profiles): RedirectResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $profiles): void {
            $user = \App\Models\User::query()->lockForUpdate()->findOrFail($this->authenticatedUser($request)->id);
            $profiles->clear($user);
            $user->save();
        });
        return redirect('/account')->with('status', 'Pending email change cancelled.');
    }
    /**
     * @param Request $request Signed capability request.
     * @param AccountProfile $profiles Email coordinator.
     * @param string $token Opaque capability.
     * @return Response Review without mutation.
     */
    public function review(Request $request, AccountProfile $profiles, string $token): Response
    {
        $user = $this->authenticatedUser($request);
        abort_unless($profiles->valid($user->refresh(), $token), 403);
        return Inertia::render('Account/ConfirmEmail', ['confirmationUrl' => $request->getRequestUri(),'pendingEmail' => $user->pending_email]);
    }
    /**
     * @param Request $request Signed CSRF-protected confirmation.
     * @param AccountProfile $profiles Email coordinator.
     * @param string $token Opaque capability.
     * @return RedirectResponse New identity acknowledgement.
     */
    public function confirm(Request $request, AccountProfile $profiles, string $token): RedirectResponse
    {
        $profiles->confirm($this->authenticatedUser($request), $token);
        return redirect('/account')->with('status', 'Sign-in email updated.');
    }
}
