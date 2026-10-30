<?php

declare(strict_types=1);

/** Verify session-only private channel grants, current membership and native Reverb acceptance on isolated PostgreSQL. */

use App\Broadcasting\TeamChannel;
use App\Exceptions\MissingTenantContext;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Reverb\ConfigApplicationProvider;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Laravel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Laravel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Laravel\Sanctum\Sanctum;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->freezeTime();
    Queue::fake();
    $this->team = Team::factory()->create();
    $this->other = Team::factory()->create();
    // An empty fake transport fails immediately if authorization accidentally attempts a Reverb network request.
    config(['broadcasting.connections.reverb.client_options.handler' => HandlerStack::create(new MockHandler([]))]);
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Tenant receiving the actor.
 * @param string $role Persisted role; owner uses the team's primary owner.
 * @return User Verified current member with a deliberately populated selected-team preference.
 */
function broadcastMember(Team $team, string $role): User
{
    if ($role === 'owner') {
        $actor = $team->owner;
    } else {
        $actor = User::factory()->create();
        $team->users()->attach($actor->id, ['role' => $role]);
    }

    $actor->forceFill(['current_team_id' => $team->id])->save();

    return $actor;
}

/**
 * @param Team $team Team encoded only in the private channel, not a separate trusted request parameter.
 * @param string $socket Native Pusher socket identity.
 * @return array{channel_name: string, socket_id: string} Canonical browser authorization input.
 */
function broadcastAuthPayload(Team $team, string $socket = '12345.67890'): array
{
    return ['channel_name' => 'private-team.'.$team->id, 'socket_id' => $socket];
}

it('grants every current team role with the exact native socket-bound signature and no private data', function (string $role): void {
    $actor = broadcastMember($this->team, $role);
    $before = $actor->getRawOriginal('current_team_id');
    $payload = broadcastAuthPayload($this->team);
    $expected = 'public-test-key:'.hash_hmac('sha256', $payload['socket_id'].':'.$payload['channel_name'], 'fake-test-secret');
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', $payload)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['auth' => $expected])
        ->assertDontSee('fake-test-secret', false)
        ->assertDontSee($actor->email, false)
        ->assertDontSee($this->team->name, false);
    expect($actor->fresh()?->getRawOriginal('current_team_id'))->toBe($before)
        ->and(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['owner', 'admin', 'member', 'viewer']);

it('denies foreign and revoked membership regardless of persisted role or preloaded teams', function (string $role, string $access): void {
    $actor = broadcastMember($this->team, $role);
    $actor->load('teams');

    if ($access === 'revoked') {
        $this->team->users()->detach($actor->id);
        $target = $this->team;
    } else {
        $target = $this->other;
    }

    $before = DB::table('users')->where('id', $actor->id)->value('current_team_id');
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', broadcastAuthPayload($target))
        ->assertForbidden()->assertJsonMissingPath('auth');
    expect(DB::table('users')->where('id', $actor->id)->value('current_team_id'))->toBe($before);
    Queue::assertNothingPushed();
})->with(['owner', 'admin', 'member', 'viewer'])->with(['foreign', 'revoked']);

it('allows another accessible team independently of the selected preference', function (): void {
    $actor = broadcastMember($this->team, 'member');
    $this->other->users()->attach($actor->id, ['role' => 'viewer']);
    $actor->load('teams');
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', broadcastAuthPayload($this->other))->assertOk();
    expect($actor->fresh()?->current_team_id)->toBe($this->team->id);
});

it('denies a new grant after a previously authorized membership is removed', function (): void {
    $actor = broadcastMember($this->team, 'member');
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', broadcastAuthPayload($this->team))->assertOk();
    $actor->load('teams');
    $this->team->users()->detach($actor->id);
    $this->postJson('/broadcasting/auth', broadcastAuthPayload($this->team, '12345.67891'))->assertForbidden()->assertJsonMissingPath('auth');
});

it('does not grant access through operator flags submitted user IDs or selected-team forgery', function (): void {
    $actor = broadcastMember($this->team, 'viewer');
    $actor->forceFill(['is_platform_admin' => true, 'current_team_id' => $this->other->id])->save();
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', [
        ...broadcastAuthPayload($this->other),
        'user_id' => $this->other->owner_id,
        'team_id' => $this->team->id,
    ])->assertForbidden()->assertJsonMissingPath('auth');
});

it('returns the same denial for a foreign team and an absent team', function (): void {
    $actor = broadcastMember($this->team, 'viewer');
    $foreign = $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', broadcastAuthPayload($this->other))->assertForbidden();
    $missing = $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-team.999999999999999999',
        'socket_id' => '12345.67890',
    ])->assertForbidden();
    expect($missing->json())->toBe($foreign->json());
});

it('rejects unauthenticated unverified and bearer-only identities before signing', function (string $identity): void {
    $actor = broadcastMember($this->team, 'member');

    if ($identity === 'unverified') {
        $actor->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($actor, 'web');
    } elseif ($identity === 'bearer') {
        $token = $actor->createToken('Synthetic broadcast token', ['*']);
        $this->withToken($token->plainTextToken);
    } elseif ($identity === 'sanctum-guard') {
        Sanctum::actingAs($actor, ['*'], 'sanctum');
        Auth::guard('web')->logout();
    }

    $this->postJson('/broadcasting/auth', broadcastAuthPayload($this->team))
        ->assertStatus($identity === 'unverified' ? 403 : 401)->assertJsonMissingPath('auth');
})->with(['guest', 'unverified', 'bearer', 'sanctum-guard']);

it('rejects malformed or unsupported channel and socket formats without issuing any grant', function (array $input): void {
    $actor = broadcastMember($this->team, 'owner');
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', $input)->assertUnprocessable()->assertJsonMissingPath('auth');
})->with([
    'missing' => [[]],
    'public' => [['channel_name' => 'team.1', 'socket_id' => '1.2']],
    'presence' => [['channel_name' => 'presence-team.1', 'socket_id' => '1.2']],
    'user' => [['channel_name' => 'private-App.Models.User.1', 'socket_id' => '1.2']],
    'suffix' => [['channel_name' => 'private-team.1.extra', 'socket_id' => '1.2']],
    'zero' => [['channel_name' => 'private-team.0', 'socket_id' => '1.2']],
    'negative' => [['channel_name' => 'private-team.-1', 'socket_id' => '1.2']],
    'leading zero' => [['channel_name' => 'private-team.01', 'socket_id' => '1.2']],
    'channel whitespace' => [['channel_name' => ' private-team.1 ', 'socket_id' => '1.2']],
    'channel newline' => [['channel_name' => "private-team.1\n", 'socket_id' => '1.2']],
    'missing socket part' => [['channel_name' => 'private-team.1', 'socket_id' => '123']],
    'socket letters' => [['channel_name' => 'private-team.1', 'socket_id' => 'a.b']],
    'socket overflow length' => [['channel_name' => 'private-team.1', 'socket_id' => str_repeat('1', 21).'.2']],
    'socket whitespace' => [['channel_name' => 'private-team.1', 'socket_id' => ' 1.2 ']],
    'array channel' => [['channel_name' => ['private-team.1'], 'socket_id' => '1.2']],
    'array socket' => [['channel_name' => 'private-team.1', 'socket_id' => ['1.2']]],
    'JSONP' => [['channel_name' => 'private-team.1', 'socket_id' => '1.2', 'callback' => 'stealGrant']],
]);

it('refuses a canonical-looking channel ID outside the supported integer range', function (): void {
    $this->actingAs($this->team->owner, 'web')->postJson('/broadcasting/auth', [
        'channel_name' => 'private-team.9223372036854775808', 'socket_id' => '1.2',
    ])->assertForbidden()->assertJsonMissingPath('auth');
});

it('fails closed with a safe response when signing credentials or driver are unavailable', function (string $field, mixed $value): void {
    config(['broadcasting.connections.reverb.'.$field => $value]);
    $this->actingAs($this->team->owner, 'web')->postJson('/broadcasting/auth', broadcastAuthPayload($this->team))
        ->assertServiceUnavailable()->assertJsonPath('message', 'Broadcast authorization is unavailable.')->assertJsonMissingPath('auth');
})->with([
    ['key', null], ['key', ''], ['secret', null], ['secret', '   '], ['app_id', null], ['driver', 'log'],
]);

it('binds the issued signature to exactly one socket and team', function (): void {
    $actor = broadcastMember($this->team, 'viewer');
    $payload = broadcastAuthPayload($this->team);
    $grant = $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', $payload)->assertOk()->json('auth');
    $changedSocket = 'public-test-key:'.hash_hmac('sha256', '12345.99999:'.$payload['channel_name'], 'fake-test-secret');
    $changedTeam = 'public-test-key:'.hash_hmac('sha256', $payload['socket_id'].':private-team.'.$this->other->id, 'fake-test-secret');
    expect($grant)->not->toBe($changedSocket)->not->toBe($changedTeam);
});

it('keeps CSRF enforcement active outside the ordinary test bypass', function (): void {
    $this->actingAs($this->team->owner, 'web');
    app()->instance('env', 'production');

    try {
        $this->postJson('/broadcasting/auth', broadcastAuthPayload($this->team))->assertStatus(419)->assertJsonMissingPath('auth');
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->postJson('/broadcasting/auth', [
            ...broadcastAuthPayload($this->team), '_token' => $token,
        ])->assertOk();
    } finally {
        app()->instance('env', 'testing');
    }
});

it('uses grants accepted by the real Reverb private channel verifier and refuses tampering', function (string $tamper): void {
    $application = (new ConfigApplicationProvider(collect(config('reverb.apps.apps'))))->findById('signalbox-test');
    $socket = Mockery::mock(WebSocketConnection::class);
    $connection = new Connection($socket, $application, 'http://localhost:8000');
    $socketId = $connection->id();
    $payload = broadcastAuthPayload($this->team, $tamper === 'socket' ? $socketId.'0' : $socketId);
    $grant = $this->actingAs($this->team->owner, 'web')->postJson('/broadcasting/auth', $payload)->assertOk()->json('auth');
    app()->bind(ChannelConnectionManager::class, ArrayChannelConnectionManager::class);
    $channel = new PrivateChannel($tamper === 'channel' ? 'private-team.'.$this->other->id : $payload['channel_name']);

    if ($tamper === 'signature') {
        $grant = 'public-test-key:'.str_repeat('0', 64);
    }

    if ($tamper !== 'none') {
        expect(fn () => $channel->subscribe($connection, $grant))->toThrow(ConnectionUnauthorized::class);

        return;
    }

    $channel->subscribe($connection, $grant);
    expect($channel->subscribed($connection))->toBeTrue();
})->with(['none', 'socket', 'channel', 'signature']);

it('keeps denied and invalid responses private and refuses JSONP from the query string', function (): void {
    $actor = broadcastMember($this->team, 'viewer');
    $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', broadcastAuthPayload($this->other))
        ->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson('/broadcasting/auth?callback=stealGrant', broadcastAuthPayload($this->team))
        ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('auth');
});

it('checks direct channel callbacks independently of another active tenant context', function (): void {
    $actor = broadcastMember($this->team, 'viewer');
    app(TenantContext::class)->run($this->other->id, function () use ($actor): void {
        expect(app(TeamChannel::class)->join($actor, (string) $this->team->id))->toBeTrue()
            ->and(app(TeamChannel::class)->join($actor, (string) $this->other->id))->toBeFalse()
            ->and(app(TenantContext::class)->requireTeamId())->toBe($this->other->id);
    });
});
