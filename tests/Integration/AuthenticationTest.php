<?php

/** Exercise complete web authentication against the guarded PostgreSQL test database. */

use App\Actions\Identity\RegisterUser;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthFixture;

pest()->group('docker');
beforeEach(/** Guard the database lifecycle and isolate rate/replay state between cases. */ function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
    $this->withoutVite();
    Cache::flush();
    Notification::fake();
});

it('registers an unverified account and complete team before sending verification', /** Check the actual public form action and post-commit notification. */ function (): void {
    $this->post('/register', ['name' => 'First Owner', 'team_name' => 'Example team', 'email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123', 'owner_id' => 999, 'plan' => 'business'])
        ->assertRedirect('/email/verify')->assertSessionHasNoErrors();
    /** @var User $user Newly authenticated unverified owner. */
    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('owner@example.test')->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('secure-password-123', $user->password))->toBeTrue()
        ->and(Team::query()->sole()->owner_id)->toBe($user->id)
        ->and(Membership::query()->sole()->role->value)->toBe('owner')
        ->and($user->resolveCurrentTeam()?->timezone)->toBe('UTC')
        ->and($user->resolveCurrentTeam()?->plan->value)->toBe('free');
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get('/account')->assertRedirect('/email/verify');
    $this->get('/account/security')->assertRedirect('/email/verify');
});

it('permits duplicate team names with independent generated slugs', /** Registration must not treat presentation names as team identities. */ function (): void {
    app(RegisterUser::class)('One', 'one@example.test', 'secure-password-123', 'Same team');
    app(RegisterUser::class)('Two', 'two@example.test', 'secure-password-123', 'Same team');
    expect(Team::query()->count())->toBe(2)->and(Team::query()->pluck('slug')->unique())->toHaveCount(2);
});

it('rejects invalid registration without writing any aggregate', /** @param array<string, string> $changes Invalid public fields. */ function (array $changes): void {
    $this->post('/register', array_merge(['name' => 'Name', 'team_name' => 'Team', 'email' => 'owner@example.test', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'], $changes))->assertSessionHasErrors();
    expect(User::query()->count())->toBe(0)->and(Team::query()->count())->toBe(0)->and(Membership::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    [['name' => ' ']], [['team_name' => ' ']], [['email' => 'invalid']],
    [['password' => 'short', 'password_confirmation' => 'short']],
    [['password_confirmation' => 'mismatch']],
    [['password' => str_repeat('x', 129), 'password_confirmation' => str_repeat('x', 129)]],
]);

it('rejects case variants at both validation and PostgreSQL uniqueness boundaries', /** A raw competing insert cannot bypass normalized-email uniqueness. */ function (): void {
    User::factory()->create(['email' => 'owner@example.test']);
    $this->post('/register', ['name' => 'Name', 'team_name' => 'Team', 'email' => 'OWNER@EXAMPLE.TEST', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])->assertSessionHasErrors('email');
    expect(fn () => DB::transaction(/** Attempt a raw write outside the User email mutator. */ fn () => DB::table('users')->insert(['name' => 'Duplicate', 'email' => 'OWNER@EXAMPLE.TEST', 'password' => 'unused'])))->toThrow(QueryException::class);
    expect(User::query()->count())->toBe(1)->and(Team::query()->count())->toBe(0);
});

it('rolls back registration and suppresses notifications if team membership creation fails', /** Inject a real database failure after the user and team insertions. */ function (): void {
    DB::statement("ALTER TABLE team_user ADD CONSTRAINT fixture_reject_registration CHECK (role <> 'owner')");
    try {
        expect(fn () => app(RegisterUser::class)('Owner', 'owner@example.test', 'secure-password-123', 'Team'))->toThrow(QueryException::class);
        expect(User::query()->count())->toBe(0)->and(Team::query()->count())->toBe(0);
        Notification::assertNothingSent();
    } finally {
        DB::statement('ALTER TABLE team_user DROP CONSTRAINT fixture_reject_registration');
    }
});

it('logs in with normalized email and revokes the session on logout', /** Check explicit login, password hash tracking and logout invalidation. */ function (): void {
    /** @var User $user Verified account with a known fixture password. */
    $user = User::factory()->create();
    $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'password', 'remember' => true])->assertRedirect('/account');
    $this->assertAuthenticatedAs($user);
    expect(session()->get('password_hash_web'))->toBe($user->password);
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
    $this->get('/account')->assertRedirect('/login');
});

it('rejects incorrect credentials and limits repeated attempts', /** Missing and incorrect accounts share the same validation behavior. */ function (): void {
    /** @var User $user Existing account used for a bounded brute-force sequence. */
    $user = User::factory()->create();
    /** @var int $attempt Number of the attempted credential submission. */
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
    $this->assertGuest();
});

it('keeps unverified users confined to verification and logout', /** Verification status protects account pages and every second-factor endpoint. */ function (): void {
    /** @var User $user Authenticated but unverified identity. */
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get('/email/verify')->assertOk();
    $this->post('/email/verification-notification')->assertRedirect();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get('/account')->assertRedirect('/email/verify');
    $this->post('/user/two-factor-authentication')->assertRedirect('/email/verify');
    $this->getJson('/user/two-factor-recovery-codes')->assertStatus(403);
});

it('verifies only the authenticated identity with an unexpired signed link', /** A signed link does not authorize a different user or modified email hash. */ function (): void {
    /** @var User $user Unverified recipient. */
    $user = User::factory()->unverified()->create();
    /** @var string $url Standard signed verification URL tied to identity and email. */
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs(User::factory()->unverified()->create())->get($url)->assertForbidden();
    $this->actingAs($user)->get($url.'&tampered=1')->assertForbidden();
    $this->actingAs($user)->get($url)->assertRedirect('/account?verified=1');
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
    $this->get('/account')->assertOk();
});

it('rejects expired verification links without modifying the account', /** Control the exact expiration boundary without waiting. */ function (): void {
    /** @var User $user Intended verification recipient. */
    $user = User::factory()->unverified()->create();
    /** @var string $url Link that expires after sixty minutes. */
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->travel(61)->minutes();
    $this->actingAs($user)->get($url)->assertForbidden();
    expect($user->fresh()?->email_verified_at)->toBeNull();
});

it('returns identical reset acknowledgements and throttles notification delivery', /** Broker throttle and nonexistent users never disclose membership or identity. */ function (): void {
    /** @var User $user Known recipient of a password reset message. */
    $user = User::factory()->create();
    /** @var string $message Uniform public acknowledgement. */
    $message = 'If an account exists for that email, a password reset link has been sent.';
    $this->postJson('/forgot-password', ['email' => strtoupper($user->email)])->assertOk()->assertExactJson(['message' => $message]);
    $this->postJson('/forgot-password', ['email' => $user->email])->assertOk()->assertExactJson(['message' => $message]);
    $this->postJson('/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->assertExactJson(['message' => $message]);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

it('resets a password once while preserving 2FA and rotating remember credentials', /** Exercise the password broker through the actual reset endpoint. */ function (): void {
    /** @var User $user Existing account with active second-factor protection. */
    $user = AuthFixture::enroll(User::factory()->create());
    /** @var string $token Single-use password broker credential. */
    $token = Password::broker()->createToken($user);
    /** @var string|null $remember Original persistent-session credential. */
    $remember = $user->remember_token;
    /** @var array<string, string> $input Valid password reset request reused to verify consumption. */
    $input = ['token' => $token, 'email' => $user->email, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
    $this->post('/reset-password', $input)->assertRedirect('/login')->assertSessionHasNoErrors();
    expect(Hash::check('new-secure-password', $user->fresh()?->password ?? ''))->toBeTrue()
        ->and($user->fresh()?->remember_token)->not->toBe($remember)
        ->and($user->fresh()?->two_factor_secret)->toBe($user->two_factor_secret)
        ->and($user->fresh()?->hasEnabledTwoFactorAuthentication())->toBeTrue();
    $this->assertGuest();
    $this->post('/reset-password', $input)->assertSessionHasErrors('email');
});

it('rejects expired reset tokens and invalid new passwords', /** The broker lifetime and shared password policy are independent protections. */ function (): void {
    /** @var User $user Existing password reset recipient. */
    $user = User::factory()->create();
    /** @var string $token Opaque broker token, not stored in cleartext by the database. */
    $token = Password::broker()->createToken($user);
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->travel(61)->minutes();
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
    expect($user->fresh()?->password)->toBe($user->password);
});

it('revokes a password-authenticated session even before its first account visit', /** Login immediately records the password hash used by AuthenticateSession. */ function (): void {
    /** @var User $user Session owner before an independent password reset. */
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/account');
    $user->forceFill(['password' => 'changed-outside-session'])->save();
    Auth::forgetGuards();
    $this->get('/account')->assertRedirect('/login');
    $this->assertGuest();
});

it('shows only an accessible team and explicit safe account props', /** Direct or revoked current-team identifiers do not expose another tenant. */ function (): void {
    /** @var User $user Verified account with a deliberately foreign preference. */
    $user = AuthFixture::enroll(User::factory()->create());
    /** @var Team $foreign Private team not accessible through membership. */
    $foreign = Team::factory()->create();
    $user->current_team_id = $foreign->id;
    $user->save();
    $this->actingAs($user)->get('/account')->assertOk()->assertInertia(/** @param AssertableInertia $page Safe account response projection. */ fn (AssertableInertia $page): AssertableInertia => $page->component('Account/Overview')->where('currentTeam', null)->where('auth.user', ['name' => $user->name, 'email' => $user->email, 'isPlatformAdmin' => false])->missing('auth.user.password')->missing('auth.user.two_factor_secret'));
});

it('enforces CSRF outside the testing bypass', /** Exercise the real middleware with a missing anti-forgery credential. */ function (): void {
    app()->instance('env', 'production');
    try {
        $this->post('/register', ['name' => 'No token'])->assertStatus(419);
        expect(User::query()->count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
});

it('serializes concurrent case-variant registrations at the database index', /** Two PostgreSQL sessions cannot both create one normalized email identity. */ function (): void {
    config(['database.connections.auth_probe' => config('database.connections.pgsql')]);
    /** @var \Illuminate\Database\Connection $probe Independent session on the already guarded test database. */
    $probe = DB::connection('auth_probe');
    app(RegisterUser::class)('First', 'race@example.test', 'secure-password-123', 'First team');
    $probe->statement("SET lock_timeout = '100ms'");
    try {
        DB::setDefaultConnection('auth_probe');
        try {
            app(RegisterUser::class)('Second', 'RACE@EXAMPLE.TEST', 'secure-password-123', 'Second team');
            $this->fail('A competing insert must contend on the normalized email index.');
        } catch (QueryException $exception) {
            // Lock contention proves the unique index coordinates uncommitted case-variant inserts.
            expect($exception->errorInfo[0])->toBe('55P03');
        } finally {
            DB::setDefaultConnection('pgsql');
        }
        expect($probe->table('users')->whereRaw('lower(email) = ?', ['race@example.test'])->count())->toBe(0)
            ->and(User::query()->count())->toBe(1)
            ->and(Team::query()->count())->toBe(1);
    } finally {
        DB::setDefaultConnection('pgsql');
        DB::purge('auth_probe');
    }
});

it('rolls back authentication columns and rejects legacy email collisions without merging users', /** Reapply the incremental migration while preserving existing M1 identity and team data. */ function (): void {
    /** @var User $user Existing account whose identity must survive migration reversal. */
    $user = User::factory()->create(['email' => 'legacy@example.test']);
    /** @var Team $team Existing team retained across the authentication migration. */
    $team = Team::factory()->for($user, 'owner')->create();
    /** @var \Illuminate\Database\Migrations\Migration $migration Only the M1.02 incremental schema change. */
    $migration = require base_path('database/migrations/2026_10_20_000004_add_authentication_security_to_users.php');
    $migration->down();
    /** @var int $collision Legacy case-variant identity possible before the expression index existed. */
    $collision = DB::table('users')->insertGetId(['name' => 'Legacy collision', 'email' => 'LEGACY@EXAMPLE.TEST', 'password' => 'unused']);
    try {
        expect(fn () => $migration->up())->toThrow(LogicException::class, 'Resolve case-insensitive email collisions');
        expect(DB::table('users')->count())->toBe(2)->and(Team::query()->find($team->id)?->owner_id)->toBe($user->id);
    } finally {
        DB::table('users')->where('id', $collision)->delete();
        $migration->up();
    }
    expect($user->fresh()?->two_factor_secret)->toBeNull()
        ->and($user->fresh()?->password)->toBe($user->password)
        ->and($user->teams()->whereKey($team->id)->exists())->toBeTrue();
});

it('serializes password reset token consumption against competing reset requests', /** Hold a successful reset uncommitted while a second database session attempts the same token. */ function (): void {
    config(['database.connections.reset_probe' => config('database.connections.pgsql')]);
    /** @var \Illuminate\Database\Connection $probe Independent test-only connection with committed fixture data. */
    $probe = DB::connection('reset_probe');
    /** @var User $user Reset recipient visible to both sessions, removed explicitly afterward. */
    $user = User::factory()->make();
    $user->setConnection('reset_probe');
    $user->save();
    $user->setConnection('pgsql');
    /** @var string $token Broker token created outside the test transaction for both sessions. */
    $token = \Illuminate\Support\Str::random(64);
    $probe->table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make($token), 'created_at' => now()]);
    /** @var array<string, string> $input A valid reset submitted by two independent actors holding the same link. */
    $input = ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
    DB::beginTransaction();
    try {
        $this->post('/reset-password', $input)->assertRedirect('/login');
        $probe->statement("SET lock_timeout = '100ms'");
        DB::setDefaultConnection('reset_probe');
        try {
            /** @var \App\Http\Requests\Auth\SecurityInputRequest $request Already validated reset shape passed to the same application controller. */
            $request = \App\Http\Requests\Auth\SecurityInputRequest::create('/reset-password', 'POST', $input);
            app(\App\Http\Controllers\Auth\SecurityController::class)->reset($request, app(\Laravel\Fortify\Http\Controllers\NewPasswordController::class));
            $this->fail('A competing reset must wait before checking and consuming its token.');
        } catch (QueryException $exception) {
            // The second reset cannot pass token validation using uncommitted credential state.
            expect($exception->errorInfo[0])->toBe('55P03');
        } finally {
            DB::setDefaultConnection('pgsql');
        }
        expect(Hash::check('new-secure-password', $user->fresh()?->password ?? ''))->toBeTrue();
    } finally {
        DB::setDefaultConnection('pgsql');
        DB::rollBack();
        $probe->table('password_reset_tokens')->where('email', $user->email)->delete();
        $probe->table('users')->where('id', $user->id)->delete();
        DB::purge('reset_probe');
    }
});
