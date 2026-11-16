<?php

declare(strict_types=1);

/** Exercise signed private-team admission and live revocation through native Reverb and isolated PostgreSQL. */

use App\Actions\Identity\ManageTeamMembers;
use App\Enums\Identity\TeamRole;
use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Events\Broadcasting\TeamUpdate;
use App\Events\Monitoring\CheckCompleted;
use App\Models\Team;
use App\Models\User;
use App\Services\Broadcasting\TeamChannelManager;
use App\Services\Broadcasting\TeamPrivateChannel;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;
use Laravel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Laravel\Reverb\Servers\Reverb\Factory;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\RecordingReverbSocket;
use Tests\Support\ReverbAcceptance;
use Tests\TestCase;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    Queue::fake();
    config(['broadcasting.default' => 'reverb']);
    // The real factory must retain the production binding; do not replace the manager with a test-only guard.
    Factory::makePusherRouter('');
    expect(app(ChannelManager::class))->toBeInstanceOf(TeamChannelManager::class);
    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->team->users()->attach($this->member->id, ['role' => 'member']);
});

/**
 * @param TestCase $test HTTP harness using the same production signer as a verified browser session.
 * @param int $teamId Explicit tenant to subscribe to.
 * @param User $actor Current member whose own ID is signed by the server.
 * @param int $socketId Distinct in-memory transport ID.
 * @return array{Connection, RecordingReverbSocket, array{auth: string, channel_data: string}} Admitted native connection and grant.
 */
function membershipSocket(TestCase $test, int $teamId, User $actor, int $socketId): array
{
    $socket = new RecordingReverbSocket($socketId);
    $connection = ReverbAcceptance::connection($socket);
    $channel = 'private-team.'.$teamId;
    $grant = $test->actingAs($actor, 'web')->postJson('/broadcasting/auth', [
        'channel_name' => $channel, 'socket_id' => $connection->id(),
        // Submitted identities cannot replace the session actor in the signed metadata.
        'user_id' => 999999,
    ])->assertOk()->json();
    expect(json_decode($grant['channel_data'], true, 512, JSON_THROW_ON_ERROR))->toBe(['user_id' => $actor->id]);
    app(EventHandler::class)->subscribe($connection, $channel, $grant['auth'], $grant['channel_data']);
    $socket->messages = [];

    return [$connection, $socket, $grant];
}

/**
 * @param int $teamId Explicit destination; the minimal scalar frame needs no monitor/database fixture.
 * @return BroadcastEvent Real queued producer passed through native SDK signing and Reverb dispatch.
 */
function membershipPublication(int $teamId): BroadcastEvent
{
    $now = CarbonImmutable::now('UTC');

    return new BroadcastEvent(new TeamUpdate(new CheckCompleted(
        $teamId,
        11,
        21,
        MonitorRegion::Eu,
        $now,
        1,
        CheckResultKind::Observed,
        MonitorStatus::Up,
        42,
        $now,
        $now,
    )));
}

it('stops delivery to every removed member socket while preserving authorized and foreign recipients', function (TeamRole $role, bool $selfDeparture): void {
    DB::table('team_user')->where('team_id', $this->team->id)->where('user_id', $this->member->id)->update(['role' => $role->value]);
    $this->member->load('teams');
    [$first, $firstSocket, $grant] = membershipSocket($this, $this->team->id, $this->member, 1);
    [, $secondSocket] = membershipSocket($this, $this->team->id, $this->member, 2);
    [, $ownerSocket] = membershipSocket($this, $this->team->id, $this->team->owner, 3);
    $foreign = Team::factory()->create();
    [, $foreignSocket] = membershipSocket($this, $foreign->id, $foreign->owner, 4);
    $publication = ReverbAcceptance::publication(membershipPublication($this->team->id));
    expect(ReverbAcceptance::deliver($publication))->toBe(200)
        ->and($firstSocket->messages)->toHaveCount(1)
        ->and($secondSocket->messages)->toHaveCount(1)
        ->and($ownerSocket->messages)->toHaveCount(1)
        ->and($foreignSocket->messages)->toBe([]);
    $firstSocket->messages = $secondSocket->messages = $ownerSocket->messages = [];

    app(ManageTeamMembers::class)->remove($selfDeparture ? $this->member : $this->team->owner, $this->team->id, $this->member->id);
    expect(ReverbAcceptance::deliver($publication))->toBe(200)
        ->and($firstSocket->messages)->toBe([])
        ->and($secondSocket->messages)->toBe([])
        ->and($firstSocket->closed)->toBeTrue()
        ->and($secondSocket->closed)->toBeTrue()
        ->and($ownerSocket->messages)->toHaveCount(1)
        ->and($ownerSocket->closed)->toBeFalse()
        ->and($foreignSocket->messages)->toBe([])
        ->and($foreignSocket->closed)->toBeFalse();
    expect(fn () => app(EventHandler::class)->subscribe($first, 'private-team.'.$this->team->id, $grant['auth'], $grant['channel_data']))
        ->toThrow(ConnectionUnauthorized::class);
    expect(ReverbAcceptance::deliver(ReverbAcceptance::publication(membershipPublication($foreign->id))))->toBe(200)
        ->and($foreignSocket->messages)->toHaveCount(1);
})->with(TeamRole::cases())->with([false, true]);

it('retains existing subscriptions when a removal transaction rolls back or a role changes', function (string $change): void {
    [, $socket] = membershipSocket($this, $this->team->id, $this->member, 1);

    if ($change === 'rollback') {
        try {
            DB::transaction(function (): void {
                app(ManageTeamMembers::class)->remove($this->team->owner, $this->team->id, $this->member->id);

                throw new RuntimeException('Synthetic rollback');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Synthetic rollback');
        }
    } else {
        app(ManageTeamMembers::class)->changeRole($this->team->owner, $this->team->id, $this->member->id, TeamRole::Viewer);
    }

    expect(ReverbAcceptance::deliver(ReverbAcceptance::publication(membershipPublication($this->team->id))))->toBe(200)
        ->and($socket->messages)->toHaveCount(1)
        ->and($socket->closed)->toBeFalse();
})->with(['rollback', 'viewer']);

it('closes all channels of an invalidated socket before any later publication can use its old subscription', function (): void {
    [$connection, $socket] = membershipSocket($this, $this->team->id, $this->member, 1);
    $other = Team::factory()->create();
    $other->users()->attach($this->member->id, ['role' => 'viewer']);
    $channel = 'private-team.'.$other->id;
    $grant = $this->actingAs($this->member, 'web')->postJson('/broadcasting/auth', [
        'channel_name' => $channel, 'socket_id' => $connection->id(),
    ])->assertOk()->json();
    app(EventHandler::class)->subscribe($connection, $channel, $grant['auth'], $grant['channel_data']);
    $socket->messages = [];
    app(ManageTeamMembers::class)->remove($this->team->owner, $this->team->id, $this->member->id);
    ReverbAcceptance::deliver(ReverbAcceptance::publication(membershipPublication($this->team->id)));
    ReverbAcceptance::deliver(ReverbAcceptance::publication(membershipPublication($other->id)));
    expect($socket->messages)->toBe([])
        ->and($socket->closed)->toBeTrue()
        ->and(app(ChannelManager::class)->for($connection->app())->connections())->toBe([]);
});

it('rejects tampered metadata and legacy grants without subscribing the socket', function (string $tamper): void {
    [$connection, , $grant] = membershipSocket($this, $this->team->id, $this->member, 1);
    $handler = app(EventHandler::class);
    $channel = 'private-team.'.$this->team->id;
    $handler->unsubscribe($connection, $channel);

    if ($tamper === 'identity') {
        $grant['channel_data'] = json_encode(['user_id' => $this->team->owner_id], JSON_THROW_ON_ERROR);
    } elseif ($tamper === 'missing') {
        $grant['channel_data'] = null;
    } else {
        $grant['auth'] = $connection->app()->key().':'.hash_hmac('sha256', $connection->id().':'.$channel, $connection->app()->secret());
        $grant['channel_data'] = null;
    }

    expect(fn () => $handler->subscribe($connection, $channel, $grant['auth'], $grant['channel_data']))->toThrow(ConnectionUnauthorized::class);
    expect(app(ChannelManager::class)->for($connection->app())->find($channel)?->connections())->toBe([]);
})->with(['identity', 'missing', 'legacy']);

it('rejects even correctly signed metadata without a canonical verified team member', function (mixed $identity): void {
    $socket = ReverbAcceptance::connection(new RecordingReverbSocket(1));
    $channel = 'private-team.'.$this->team->id;
    $data = json_encode($identity, JSON_THROW_ON_ERROR);
    $auth = $socket->app()->key().':'.hash_hmac('sha256', $socket->id().':'.$channel.':'.$data, $socket->app()->secret());
    expect(fn () => app(EventHandler::class)->subscribe($socket, $channel, $auth, $data))->toThrow(ConnectionUnauthorized::class);
})->with([
    'null' => [null], 'list' => [[1]], 'empty' => [[]], 'string ID' => [['user_id' => '1']],
    'zero' => [['user_id' => 0]], 'missing member' => [['user_id' => PHP_INT_MAX]],
    'extra field' => [['user_id' => 1, 'role' => 'owner']],
]);

it('rechecks verification and team existence on direct and internal delivery paths', function (string $path, string $revocation): void {
    [$connection, $socket] = membershipSocket($this, $this->team->id, $this->member, 1);
    $channel = app(ChannelManager::class)->for($connection->app())->find('private-team.'.$this->team->id);
    expect($channel)->toBeInstanceOf(TeamPrivateChannel::class);

    if ($revocation === 'verification') {
        $this->member->forceFill(['email_verified_at' => null])->save();
    } else {
        $this->team->delete();
    }

    $payload = ['event' => 'CheckCompleted', 'channel' => $channel->name(), 'data' => '{}'];

    match ($path) {
        'all' => $channel->broadcastToAll($payload),
        'internal' => $channel->broadcastInternally($payload),
        'except' => $channel->broadcast($payload, ReverbAcceptance::connection(new RecordingReverbSocket(2))),
    };

    expect($socket->messages)->toBe([])->and($socket->closed)->toBeTrue();
})->with(['all', 'internal', 'except'])->with(['verification', 'deleted-team']);

it('fails closed without waiting behind an uncommitted membership removal and recovers after rollback or commit', function (bool $commit): void {
    $fixture = CommittedTeamFixture::freeTeam(0);

    try {
        [$connection, $socket] = membershipSocket($this, $fixture->teamId, $fixture->owner, 1);
        $request = ReverbAcceptance::publication(membershipPublication($fixture->teamId));
        $fixture->connection->beginTransaction();
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
        $fixture->connection->table('team_user')->where('team_id', $fixture->teamId)->delete();

        try {
            ReverbAcceptance::deliver($request);
            $this->fail('A publication must not pass an uncommitted exclusive membership lock.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[0])->toBe('55P03');
        }

        expect($socket->messages)->toBe([])->and($socket->closed)->toBeFalse()
            ->and(app(ChannelManager::class)->for($connection->app())->find('private-team.'.$fixture->teamId)?->subscribed($connection))->toBeTrue();

        if ($commit) {
            $fixture->connection->commit();
        } else {
            $fixture->connection->rollBack();
        }

        // A real Reverb publication has no enclosing RefreshDatabase transaction retaining its shared lock.
        expect($fixture->committed(fn (): int => ReverbAcceptance::deliver($request)))->toBe(200)
            ->and(count($socket->messages))->toBe($commit ? 0 : 1)
            ->and($socket->closed)->toBe($commit);
    } finally {
        while ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }

        $fixture->cleanup();
    }
})->with([false, true]);

it('holds the publication lock until the frame enters the transport and releases it afterwards', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(0);

    try {
        [, $socket] = membershipSocket($this, $fixture->teamId, $fixture->owner, 1);
        $checked = false;
        $peer = DB::connection('pgsql');
        Event::listen(MessageSent::class, function (MessageSent $event) use ($fixture, $peer, &$checked): void {
            if (!str_contains($event->message, 'CheckCompleted')) {
                return;
            }

            try {
                $peer->transaction(fn () => $peer->table('teams')->where('id', $fixture->teamId)->lock('for update nowait')->first());
                $this->fail('A membership mutation must not overtake an in-flight publication.');
            } catch (QueryException $exception) {
                expect($exception->errorInfo[0])->toBe('55P03');
                $checked = true;
            }
        });

        expect($fixture->committed(fn (): int => ReverbAcceptance::deliver(
            ReverbAcceptance::publication(membershipPublication($fixture->teamId)),
        )))->toBe(200)->and($checked)->toBeTrue()->and($socket->messages)->toHaveCount(1);

        $peer->beginTransaction();

        try {
            expect($peer->table('teams')->where('id', $fixture->teamId)->lock('for update nowait')->first())->not->toBeNull();
        } finally {
            $peer->rollBack();
        }
    } finally {
        $fixture->cleanup();
    }
});

it('fails closed on a membership query error and resumes only after authority can be read again', function (): void {
    [, $socket] = membershipSocket($this, $this->team->id, $this->member, 1);
    $request = ReverbAcceptance::publication(membershipPublication($this->team->id));
    DB::statement('ALTER TABLE team_user RENAME TO unavailable_reverb_memberships');

    try {
        expect(fn () => ReverbAcceptance::deliver($request))->toThrow(QueryException::class);
        expect($socket->messages)->toBe([])->and($socket->closed)->toBeFalse();
    } finally {
        DB::statement('ALTER TABLE unavailable_reverb_memberships RENAME TO team_user');
    }

    expect(ReverbAcceptance::deliver($request))->toBe(200)
        ->and($socket->messages)->toHaveCount(1)->and($socket->closed)->toBeFalse();
});

it('retains native public lookalikes without exposing private team frames', function (): void {
    [, $privateSocket] = membershipSocket($this, $this->team->id, $this->member, 1);
    $publicSocket = new RecordingReverbSocket(2);
    app(EventHandler::class)->subscribe(ReverbAcceptance::connection($publicSocket), 'team.'.$this->team->id);
    $publicSocket->messages = [];

    expect(ReverbAcceptance::deliver(ReverbAcceptance::publication(membershipPublication($this->team->id))))->toBe(200)
        ->and($privateSocket->messages)->toHaveCount(1)->and($publicSocket->messages)->toBe([]);
});
