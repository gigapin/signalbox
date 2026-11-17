<?php

declare(strict_types=1);

/** Exercise public registration privacy, existing-owner notices and persistence/credential invariants on PostgreSQL. */

use App\Actions\Identity\RequestPublicRegistration;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ExistingRegistrationNotice;
use App\Services\Identity\RegistrationMessages;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\AuthFixture;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\IsolatedHttpServer;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and Redis.');
    }
    $this->withoutVite();
    config(['cache.prefix' => 'registration-test:'.Str::uuid().':']);
    Cache::flush();
    Notification::fake();
});

/**
 * @param string $email Synthetic mailbox submitted to the public endpoint.
 * @return array<string, string> Valid public input with no account-owned role or credential state.
 */
function publicRegistrationInput(string $email = 'owner@example.test'): array
{
    return [
        'name' => 'Submitted Name', 'team_name' => 'Submitted team', 'email' => $email,
        'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123',
    ];
}

it('returns identical JSON for new verified and unverified accounts without authenticating any requester', function (): void {
    $verified = User::factory()->create(['email' => 'known@example.test']);
    $unverified = User::factory()->unverified()->create(['email' => 'pending@example.test']);
    foreach (['new@example.test', ' KNOWN@EXAMPLE.TEST ', 'pending@example.test'] as $email) {
        $this->postJson('/register', publicRegistrationInput($email))->assertOk()
            ->assertExactJson(['message' => RegisterController::ACKNOWLEDGEMENT])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
        expect(session()->has('password_hash_web'))->toBeFalse();
    }
    expect(User::query()->count())->toBe(3)->and(Team::query()->count())->toBe(1)->and(Membership::query()->count())->toBe(1);
    Notification::assertSentTo(User::query()->where('email', 'new@example.test')->sole(), VerifyEmail::class);
    Notification::assertSentTo($verified, ExistingRegistrationNotice::class);
    Notification::assertSentTo($unverified, ExistingRegistrationNotice::class);
    Notification::assertNotSentTo([$verified, $unverified], VerifyEmail::class);
});

it('renders the same guest confirmation after each browser registration without input or identity props', function (): void {
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => IsolatedHttpServer::inertiaVersion()]);
    foreach (['owner@example.test', ' OWNER@EXAMPLE.TEST '] as $email) {
        $this->post('/register', publicRegistrationInput($email))->assertRedirect('/register/check-email')
            ->assertSessionHasNoErrors()->assertSessionHas('status', RegisterController::ACKNOWLEDGEMENT);
        $response = $this->get('/register/check-email')->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Auth/RegistrationPending')->assertJsonPath('props.auth.user', null);
        expect($response->json('props'))->not->toHaveKeys(['email', 'created', 'delivery', 'user']);
        $this->assertGuest();
    }
});

it('preserves every credential and existing team while notifying a protected account owner', function (): void {
    $team = Team::factory()->create();
    $user = AuthFixture::enroll($team->owner);
    $user->forceFill(['remember_token' => 'unchanged-remember-token'])->save();
    $before = $user->fresh()->getAttributes();
    $membership = DB::table('team_user')->where('user_id', $user->id)->get()->toArray();
    $teamBefore = $team->fresh()->getAttributes();
    $this->postJson('/register', publicRegistrationInput(strtoupper($user->email)))->assertOk();
    expect($user->fresh()->getAttributes())->toBe($before)->and($team->fresh()->getAttributes())->toBe($teamBefore)
        ->and(DB::table('team_user')->where('user_id', $user->id)->get()->toArray())->toEqual($membership)
        ->and(User::query()->count())->toBe(1)->and(Team::query()->count())->toBe(1);
    $this->assertGuest();
    Notification::assertSentTo($user, ExistingRegistrationNotice::class);
});

it('keeps invalid-input errors independent of whether the mailbox exists', function (): void {
    User::factory()->create(['email' => 'known@example.test']);
    foreach (['new@example.test', 'known@example.test'] as $email) {
        $response = $this->postJson('/register', [...publicRegistrationInput($email), 'password_confirmation' => 'different']);
        $response->assertUnprocessable()->assertJsonValidationErrors('password')->assertJsonMissingValidationErrors('email');
    }
    Notification::assertNothingSent();
    expect(User::query()->count())->toBe(1)->and(Team::query()->count())->toBe(0);
});

it('bounds existing-owner notices across IPs atomically without extending their cooldown', function (): void {
    $user = User::factory()->create();
    $key = 'registration-existing-notice:'.$user->id;
    $store = Cache::store('redis')->getStore();
    expect($store)->toBeInstanceOf(RedisStore::class);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->postJson('/register', publicRegistrationInput($user->email))->assertOk();
    Notification::assertSentToTimes($user, ExistingRegistrationNotice::class, 1);
    $redisKey = $store->getPrefix().$key;
    expect((int) $store->connection()->ttl($redisKey))->toBeGreaterThan(50)->toBeLessThanOrEqual(60);
    $store->connection()->expire($redisKey, 10);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->postJson('/register', publicRegistrationInput($user->email))->assertOk();
    expect((int) $store->connection()->ttl($redisKey))->toBeLessThanOrEqual(10);
    Notification::assertSentToTimes($user, ExistingRegistrationNotice::class, 1);
    $store->connection()->expire($redisKey, 0);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->postJson('/register', publicRegistrationInput($user->email))->assertOk();
    Notification::assertSentToTimes($user, ExistingRegistrationNotice::class, 2);
    Cache::store('redis')->forget($key);
});

it('keeps the public per-IP limit for both new and duplicate registration requests', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/register', publicRegistrationInput())->assertOk();
    }
    $this->postJson('/register', publicRegistrationInput('another@example.test'))->assertTooManyRequests();
    expect(User::query()->count())->toBe(1)->and(Team::query()->count())->toBe(1);
});

it('does not expose cache failures or send unbounded owner notices during an outage', function (): void {
    $user = User::factory()->create();
    $cache = Mockery::mock(Factory::class);
    $cache->shouldReceive('store')->with('redis')->once()->andThrow(new RuntimeException('private cache diagnostics'));
    app()->instance(RegistrationMessages::class, new RegistrationMessages($cache));
    Log::shouldReceive('warning')->once()->with('Registration follow-up could not be delivered.');
    $this->postJson('/register', publicRegistrationInput($user->email))->assertOk()
        ->assertExactJson(['message' => RegisterController::ACKNOWLEDGEMENT]);
    Notification::assertNothingSent();
});

it('acknowledges both registration outcomes after notification failure without undoing identity persistence', function (bool $exists): void {
    if ($exists) {
        User::factory()->create(['email' => 'owner@example.test']);
    }
    Notification::shouldReceive('send')->andThrow(new RuntimeException('private transport diagnostics'));
    Log::shouldReceive('warning')->once()->with('Registration follow-up could not be delivered.');
    $this->postJson('/register', publicRegistrationInput())->assertOk()
        ->assertExactJson(['message' => RegisterController::ACKNOWLEDGEMENT]);
    $this->assertGuest();
    expect(User::query()->count())->toBe(1)->and(Team::query()->count())->toBe($exists ? 0 : 1);
})->with([true, false]);

it('does not disguise unrelated persistence errors as accepted duplicate emails', function (): void {
    DB::statement("ALTER TABLE team_user ADD CONSTRAINT fixture_registration_owner CHECK (role <> 'owner')");
    try {
        expect(fn () => app(RequestPublicRegistration::class)('Owner', 'owner@example.test', 'secure-password-123', 'Team'))
            ->toThrow(QueryException::class);
        expect(User::query()->count())->toBe(0)->and(Team::query()->count())->toBe(0);
        Notification::assertNothingSent();
    } finally {
        DB::statement('ALTER TABLE team_user DROP CONSTRAINT fixture_registration_owner');
    }
});

it('rethrows unrelated unique constraints instead of classifying them from submitted SQL bindings', function (): void {
    User::factory()->create(['name' => 'users_email_unique']);
    DB::statement('CREATE UNIQUE INDEX fixture_registration_name ON users (name)');
    try {
        expect(fn () => app(RequestPublicRegistration::class)('users_email_unique', 'new@example.test', 'secure-password-123', 'Team'))
            ->toThrow(UniqueConstraintViolationException::class);
        expect(User::query()->count())->toBe(1)->and(Team::query()->count())->toBe(0);
        Notification::assertNothingSent();
    } finally {
        DB::statement('DROP INDEX fixture_registration_name');
    }
});

it('requires normal login before verifying a newly registered email and preserves the signed intended URL', function (): void {
    $this->post('/register', publicRegistrationInput())->assertRedirect('/register/check-email');
    $user = User::query()->sole();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($url)->assertRedirect('/login');
    $this->post('/login', ['email' => $user->email, 'password' => 'secure-password-123'])->assertRedirect($url);
    $this->get($url)->assertRedirect('/dashboard?verified=1');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('hashes a hash-looking submitted password as plaintext instead of accepting a stored credential representation', function (): void {
    $plaintext = Hash::make('some-other-value');
    $this->post('/register', [...publicRegistrationInput(), 'password' => $plaintext, 'password_confirmation' => $plaintext])
        ->assertRedirect('/register/check-email');
    $stored = User::query()->sole()->password;
    expect($stored)->not->toBe($plaintext)->and(Hash::check($plaintext, $stored))->toBeTrue();
});

it('renders static owner guidance without submitted names teams passwords or verification capabilities', function (): void {
    $user = User::factory()->create(['name' => 'PRIVATE OWNER']);
    $message = (new ExistingRegistrationNotice())->toMail($user);
    $html = (string) $message->render();
    expect($message->subject)->toBe('Registration request for your account')
        ->and($message->actionUrl)->toBe(url('/login'))
        ->and($html)->not->toContain('PRIVATE OWNER', 'Submitted Name', 'Submitted team', 'secure-password-123', 'email/verify', 'signature=');
});

it('handles the lower-email PostgreSQL index even when legacy storage contains a case variant', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.test']);
    DB::table('users')->where('id', $user->id)->update(['email' => 'OWNER@EXAMPLE.TEST']);
    $this->postJson('/register', publicRegistrationInput())->assertOk()
        ->assertExactJson(['message' => RegisterController::ACKNOWLEDGEMENT]);
    expect(User::query()->count())->toBe(1)->and($user->fresh()->email)->toBe('OWNER@EXAMPLE.TEST');
    Notification::assertSentTo($user, ExistingRegistrationNotice::class);
});

it('sends no owner notice and reserves no cooldown if an enclosing transaction rolls back', function (): void {
    $user = User::factory()->create();
    try {
        DB::transaction(function () use ($user): void {
            app(RegistrationMessages::class)->existing($user);
            throw new RuntimeException('Synthetic rollback.');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Synthetic rollback.');
    }
    Notification::assertNothingSent();
    expect(Cache::store('redis')->has('registration-existing-notice:'.$user->id))->toBeFalse();
});

it('keeps authenticated requests outside public registration regardless of the submitted mailbox', function (): void {
    $user = User::factory()->create();
    foreach ([$user->email, 'unknown@example.test'] as $email) {
        $this->actingAs($user)->post('/register', publicRegistrationInput($email))->assertRedirect('/account');
    }
    expect(User::query()->count())->toBe(1)->and(Team::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('acknowledges a real concurrent registration after PostgreSQL settles the email winner', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $email = 'registration-race-'.Str::lower(Str::random(12)).'@example.test';
    $server = null;
    $promise = null;
    try {
        $server = IsolatedHttpServer::start();
        $cookies = new CookieJar();
        $multi = new CurlMultiHandler(['select_timeout' => 0.01]);
        $client = $server->client($cookies, HandlerStack::create($multi));
        $client->get('/register', ['headers' => $server->inertiaHeaders($cookies)]);
        $fixture->connection->beginTransaction();
        $fixture->committed(fn () => app(RequestPublicRegistration::class)('Concurrent owner', $email, 'secure-password-123', 'Concurrent team'));

        $promise = $client->postAsync('/register', [
            'headers' => $server->inertiaHeaders($cookies), 'form_params' => publicRegistrationInput(strtoupper($email)),
        ]);
        $deadline = microtime(true) + 5;
        do {
            $multi->tick();
            $fixture->connection->select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) $fixture->connection->scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND query ILIKE 'insert into \"users\"%'");
            if ($waiting > 0) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($waiting)->toBeGreaterThan(0);
        $fixture->connection->commit();
        $response = $promise->wait();
        expect($response->getStatusCode())->toBe(302)
            ->and(parse_url($response->getHeaderLine('Location'), PHP_URL_PATH))->toBe('/register/check-email')
            ->and($fixture->connection->table('users')->whereRaw('lower(email) = ?', [$email])->count())->toBe(1);
        $ownerId = $fixture->connection->table('users')->where('email', $email)->value('id');
        expect($fixture->connection->table('teams')->where('owner_id', $ownerId)->count())->toBe(1)
            ->and($fixture->connection->table('team_user')->where('user_id', $ownerId)->count())->toBe(1);
        $confirmation = $client->get('/register/check-email', ['headers' => $server->inertiaHeaders($cookies)]);
        $props = json_decode((string) $confirmation->getBody(), true, flags: JSON_THROW_ON_ERROR);
        expect($props['component'])->toBe('Auth/RegistrationPending')->and($props['props']['auth']['user'])->toBeNull()
            ->and($props['props']['errors'])->toBeEmpty();
        expect(parse_url($client->get('/account')->getHeaderLine('Location'), PHP_URL_PATH))->toBe('/login');
    } finally {
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }
        $promise?->cancel();
        $server?->stop();
        $ownerId = $fixture->connection->table('users')->where('email', $email)->value('id');
        if ($ownerId !== null) {
            $fixture->connection->table('teams')->where('owner_id', $ownerId)->delete();
            $fixture->connection->table('users')->where('id', $ownerId)->delete();
        }
        $fixture->cleanup();
    }
});
