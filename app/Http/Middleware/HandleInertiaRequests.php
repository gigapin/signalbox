<?php

/**
 * Connect Laravel web responses to the Inertia 2 client application.
 */

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/** Share product identity and explicit account projections without tenant or credential data. */
final class HandleInertiaRequests extends Middleware
{
    /** @var string Blade view used for the initial HTML response. */
    protected $rootView = 'app';

    /**
     * Combine standard protocol props with product name, minimal account identity and safe status.
     *
     * @param Request $request Incoming web request used by Inertia's base middleware.
     * @return array<string, mixed> Shared props serialized for the current page.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            // Serialize a deliberate identity projection rather than the Eloquent user or its relationships.
            'auth' => ['user' => $request->user() === null ? null : [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ]],
            'status' => match ($request->session()->get('status')) {
                'team-switched' => 'Current team updated.',
                'member-updated' => 'Member role updated.',
                'member-removed' => 'Membership removed.',
                'ownership-transferred' => 'Primary ownership transferred.',
                'invitation-accepted' => 'You have joined the team.',
                'invitation-sent' => 'Invitation sent. Previous links are invalid after a resend.',
                'invitation-revoked' => 'Invitation revoked.',
                'verification-link-sent' => 'A new verification link has been sent to your email.',
                'two-factor-authentication-enabled' => 'Scan the setup code, then confirm your authenticator.',
                'two-factor-authentication-confirmed' => 'Two-factor authentication is enabled.',
                'two-factor-authentication-disabled' => 'Two-factor authentication is disabled.',
                'recovery-codes-generated' => 'Recovery codes have been replaced. Save the new set.',
                default => $request->session()->get('status'),
            },
        ];
    }
}
