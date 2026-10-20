<?php

/** Verify authentication entry pages and guest boundaries without infrastructure access. */

use Inertia\Testing\AssertableInertia;

it('renders declared public authentication pages without database access', /** @param string $url Public entrypoint. @param string $component Expected explicitly declared React page. */ function (string $url, string $component): void {
    $this->withoutVite()->get($url)->assertOk()->assertInertia(/** @param AssertableInertia $page Guest-only page response. */ fn (AssertableInertia $page): AssertableInertia => $page->component($component)->where('auth.user', null));
})->with([
    ['/register', 'Auth/Register'], ['/login', 'Auth/Login'], ['/forgot-password', 'Auth/ForgotPassword'], ['/reset-password/example-token?email=owner@example.test', 'Auth/ResetPassword'],
]);

it('redirects unauthenticated account requests to login', /** @param string $url Protected destination. */ function (string $url): void {
    $this->get($url)->assertRedirect('/login');
})->with(['/account', '/account/security', '/email/verify', '/user/confirm-password', '/user/two-factor-recovery-codes']);

it('does not expose unrelated profile passkey or token endpoints', /** @param string $url Route intentionally outside M1.02. */ function (string $url): void {
    $this->get($url)->assertNotFound();
})->with(['/passkeys/login/options', '/user/passkeys/options', '/user/profile-information', '/api/tokens']);
