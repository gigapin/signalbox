<?php

/** Verify confirmed enrollment, pending-session protection and serialized recovery consumption. */

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;
use Tests\Support\AuthFixture;

pest()->group('docker');
beforeEach(/** Keep second-factor tests on the isolated PostgreSQL database with fresh cache state. */ function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
    $this->withoutVite();
    Cache::flush();
});

it('requires a recent password before any second-factor settings are accessible', /** UI and direct JSON endpoints enforce the same recent-password boundary. */ function (): void {
    /** @var User $user Verified account that has not confirmed its password recently. */
    $user = User::factory()->create();
    $this->actingAs($user)->get('/account/security')->assertRedirect('/user/confirm-password');
    $this->getJson('/user/two-factor-recovery-codes')->assertStatus(423);
    $this->post('/user/two-factor-authentication')->assertRedirect('/user/confirm-password');
    $this->post('/user/confirm-password', ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post('/user/confirm-password', ['password' => 'password'])->assertRedirect('/account/security');
    $this->get('/account/security')->assertOk();
    $this->travel(901)->seconds();
    $this->get('/account/security')->assertRedirect('/user/confirm-password');
});

it('generates encrypted pending setup and confirms only a valid current token', /** Actual HTTP enrollment keeps authenticator details out of account page props. */ function (): void {
    /** @var User $user Verified owner of a fresh authenticator setup. */
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/user/two-factor-authentication')->assertRedirect();
    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($user->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'password']);
    /** @var string $secret Plaintext enrollment value, compared only in test memory. */
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    expect($user->two_factor_secret)->not->toBe($secret);
    $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg'])->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/user/two-factor-secret-key')->assertOk()->assertJsonPath('secretKey', $secret)->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/account/security')->assertInertia(/** @param AssertableInertia $page Enrollment flags without QR, secret or recovery data. */ fn (AssertableInertia $page): AssertableInertia => $page->component('Account/Security')->where('twoFactorPending', true)->where('twoFactorEnabled', false)->missing('secretKey')->missing('two_factor_secret')->missing('recoveryCodes'));
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => AuthFixture::code($user, -5)])->assertUnprocessable();
    expect($user->fresh()?->two_factor_confirmed_at)->toBeNull();
    $this->post('/user/confirmed-two-factor-authentication', ['code' => AuthFixture::code($user)])->assertRedirect()->assertSessionHasNoErrors();
    expect($user->fresh()?->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('allows password-only access while enrollment is still pending', /** A generated but unconfirmed secret must not lock the user out. */ function (): void {
    /** @var User $user Identity that has not completed enrollment. */
    $user = AuthFixture::enroll(User::factory()->create(), false);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/account');
    $this->assertAuthenticatedAs($user);
});

it('keeps a confirmed user unauthenticated until a valid second factor is supplied', /** Password acceptance creates a pending session, not a privileged browser identity. */ function (): void {
    /** @var User $user Verified account with confirmed authenticator fixture. */
    $user = AuthFixture::enroll(User::factory()->create());
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
    $this->get('/account')->assertRedirect('/login');
    $this->get('/two-factor-challenge')->assertOk()->assertInertia(/** @param AssertableInertia $page Pending guest response without account data. */ fn (AssertableInertia $page): AssertableInertia => $page->component('Auth/TwoFactorChallenge')->where('auth.user', null));
    $this->post('/two-factor-challenge', ['code' => AuthFixture::code($user, -5)])->assertSessionHasErrors('code');
    $this->assertGuest();
    $this->post('/two-factor-challenge', ['code' => AuthFixture::code($user)])->assertRedirect('/account');
    $this->assertAuthenticatedAs($user);
    expect(session()->has('login'))->toBeFalse();
});

it('rejects pending challenges after expiry or credential changes', /** @param string $change Transition invalidating the server-owned password snapshot. */ function (string $change): void {
    /** @var User $user Account whose pending password challenge will become invalid. */
    $user = AuthFixture::enroll(User::factory()->create());
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    match ($change) {
        'expiry' => $this->travel(300)->seconds(),
        'password' => $user->forceFill(['password' => 'changed-password-123'])->save(),
        'secret' => $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save(),
    };
    $this->post('/two-factor-challenge', ['recovery_code' => $user->recoveryCodes()[0]])->assertRedirect('/login');
    $this->assertGuest();
    expect(session()->has('login'))->toBeFalse();
})->with(['expiry', 'password', 'secret']);

it('rejects a challenge without a server-owned pending identity', /** Supplying a user identifier in the payload does not establish a challenge. */ function (): void {
    /** @var User $user Unrelated identity whose ID is deliberately supplied by an attacker. */
    $user = AuthFixture::enroll(User::factory()->create());
    $this->post('/two-factor-challenge', ['user_id' => $user->id, 'recovery_code' => $user->recoveryCodes()[0]])->assertRedirect('/login');
    $this->assertGuest();
});

it('consumes a recovery code exactly once across separate pending login sessions', /** A second password challenge reads updated recovery state rather than a stale snapshot. */ function (): void {
    /** @var User $user Confirmed authenticator owner. */
    $user = AuthFixture::enroll(User::factory()->create());
    /** @var string $recovery Single-use recovery credential stored only in test memory. */
    $recovery = $user->recoveryCodes()[0];
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect('/account');
    expect($user->fresh()?->recoveryCodes())->not->toContain($recovery);
    $this->post('/logout')->assertRedirect('/');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('rejects replayed authenticator steps and accepts a new controlled time step', /** Replay tracking is scoped to the secret and uses Laravel time instead of wall-clock sleeps. */ function (): void {
    /** @var User $user Confirmed authenticator owner. */
    $user = AuthFixture::enroll(User::factory()->create());
    /** @var string $code Token for the current time step. */
    $code = AuthFixture::code($user);
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/account');
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/two-factor-challenge')->assertSessionHasErrors('code');
    $this->travel(30)->seconds();
    $this->post('/two-factor-challenge', ['code' => AuthFixture::code($user)])->assertRedirect('/account');
});

it('regenerates recovery credentials and clears all second-factor data on disable', /** Management changes apply only to the authenticated actor, ignoring injected user IDs. */ function (): void {
    /** @var User $user Account changing its own security settings. */
    $user = AuthFixture::enroll(User::factory()->create());
    /** @var User $other Unrelated account that must remain unaffected. */
    $other = AuthFixture::enroll(User::factory()->create());
    /** @var array<int, string> $old Previously valid recovery set. */
    $old = $user->recoveryCodes();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->getJson('/user/two-factor-recovery-codes')->assertOk()->assertExactJson($old)->assertHeader('Cache-Control', 'no-store, private');
    $this->post('/user/two-factor-recovery-codes', ['user_id' => $other->id])->assertRedirect();
    expect(array_intersect($old, $user->fresh()?->recoveryCodes() ?? []))->toBe([])
        ->and($other->fresh()?->two_factor_secret)->toBe($other->two_factor_secret);
    $this->delete('/user/two-factor-authentication', ['user_id' => $other->id])->assertRedirect();
    expect($user->fresh()?->two_factor_secret)->toBeNull()
        ->and($user->fresh()?->two_factor_recovery_codes)->toBeNull()
        ->and($user->fresh()?->two_factor_confirmed_at)->toBeNull()
        ->and($other->fresh()?->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('rejects ambiguous challenge credentials and never flashes submitted codes', /** Both methods at once, malformed TOTP and oversized recovery inputs fail before authentication. */ function (): void {
    /** @var User $user Pending identity for input validation checks. */
    $user = AuthFixture::enroll(User::factory()->create());
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => '123456', 'recovery_code' => 'private-recovery'])->assertSessionHasErrors()->assertSessionMissing('_old_input.code')->assertSessionMissing('_old_input.recovery_code');
    $this->post('/two-factor-challenge', ['code' => '123'])->assertSessionHasErrors('code');
    $this->post('/two-factor-challenge', ['recovery_code' => str_repeat('x', 256)])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('throttles second-factor attempts without logging the user in', /** The rate key comes from the pending session, independently of attacker-supplied IDs. */ function (): void {
    /** @var User $user Identity under a bounded unsuccessful challenge sequence. */
    $user = AuthFixture::enroll(User::factory()->create());
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    /** @var int $attempt Number of the deliberately unsuccessful challenge. */
    foreach (range(1, 5) as $attempt) {
        $this->post('/two-factor-challenge', ['recovery_code' => 'not-a-valid-code'])->assertSessionHasErrors();
    }
    $this->post('/two-factor-challenge', ['recovery_code' => $user->recoveryCodes()[0]])->assertStatus(429);
    $this->assertGuest();
});

it('holds the user row lock while consuming recovery credentials across two sessions', /** A competing challenge cannot read and replace the same recovery set concurrently. */ function (): void {
    config(['database.connections.security_probe' => config('database.connections.pgsql')]);
    /** @var \Illuminate\Database\Connection $probe Independent session with a bounded lock wait. */
    $probe = \Illuminate\Support\Facades\DB::connection('security_probe');
    /** @var User $user Committed fixture visible to both sessions; explicitly removed in cleanup. */
    $user = User::factory()->make();
    $user->setConnection('security_probe');
    $user->save();
    AuthFixture::enroll($user);
    $user->setConnection('pgsql');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    /** @var array<string, mixed> $pending A second session can hold the same legitimately password-verified challenge. */
    $pending = session()->get('login');
    /** @var string $code Original recovery credential subject to the race. */
    $code = $user->recoveryCodes()[0];
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $this->post('/two-factor-challenge', ['recovery_code' => $code])->assertRedirect('/account');
        /** @var \Illuminate\Session\Store $session Independent server-managed pending session for the contender. */
        $session = new \Illuminate\Session\Store('security-contender', new \Illuminate\Session\ArraySessionHandler(120));
        $session->start();
        $session->put('login', $pending);
        /** @var \Illuminate\Http\Request $request Competing challenge bound to the declared route, not a client-selected identity. */
        $request = \Illuminate\Http\Request::create('/two-factor-challenge', 'POST', ['recovery_code' => $code]);
        $request->setLaravelSession($session);
        $request->setRouteResolver(/** Resolve the actual challenge route so its security rules remain in effect. */ fn () => \Illuminate\Support\Facades\Route::getRoutes()->getByName('two-factor.login.store'));
        $probe->statement("SET lock_timeout = '100ms'");
        \Illuminate\Support\Facades\DB::setDefaultConnection('security_probe');
        try {
            app(\App\Http\Middleware\ProtectTwoFactorState::class)->handle($request, /** No challenge controller may run before obtaining the shared lock. */ fn () => response('', 204));
            $this->fail('Competing recovery consumption must wait for the user row lock.');
        } catch (\Illuminate\Database\QueryException $exception) {
            // PostgreSQL refuses the contender before it can consume stale recovery data.
            expect($exception->errorInfo[0])->toBe('55P03');
        } finally {
            \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');
        }
        expect($user->fresh()?->recoveryCodes())->not->toContain($code);
    } finally {
        \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');
        \Illuminate\Support\Facades\DB::rollBack();
        $probe->table('users')->where('id', $user->id)->delete();
        \Illuminate\Support\Facades\DB::purge('security_probe');
    }
});
