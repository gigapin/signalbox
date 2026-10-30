<?php

declare(strict_types=1);

/** Exercise installed Reverb admission, signed publication and channel isolation through in-memory transports. */

use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Events\Broadcasting\TeamUpdate;
use App\Events\Monitoring\CheckCompleted;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Broadcasting\BroadcastEvent;
use Laravel\Reverb\Protocols\Pusher\ClientEvent;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;
use Laravel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Laravel\Reverb\Protocols\Pusher\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RecordingReverbSocket;
use Tests\Support\ReverbAcceptance;

beforeEach(function (): void {
    ReverbAcceptance::isolate();
    config(['broadcasting.default' => 'reverb']);
});

/** @return BroadcastEvent Real minimal producer frame, independent of database fixtures. */
function securityPublication(): BroadcastEvent
{
    $now = CarbonImmutable::now('UTC');

    return new BroadcastEvent(new TeamUpdate(new CheckCompleted(
        7,
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

it('keeps private publications away from a public lookalike and another authorized private channel', function (): void {
    $ownSocket = new RecordingReverbSocket(1);
    $otherSocket = new RecordingReverbSocket(2);
    $publicSocket = new RecordingReverbSocket(3);
    $own = ReverbAcceptance::connection($ownSocket);
    $other = ReverbAcceptance::connection($otherSocket);
    $public = ReverbAcceptance::connection($publicSocket);
    $handler = app(EventHandler::class);
    $secret = ReverbAcceptance::application()->secret();
    $key = ReverbAcceptance::application()->key();
    $grant = $key.':'.hash_hmac('sha256', $own->id().':private-team.7', $secret);
    $handler->subscribe($own, 'private-team.7', $grant);
    $handler->subscribe($own, 'private-team.7', $grant);
    $handler->subscribe($other, 'private-team.8', $key.':'.hash_hmac('sha256', $other->id().':private-team.8', $secret));
    $handler->subscribe($public, 'team.7');
    $ownSocket->messages = $otherSocket->messages = $publicSocket->messages = [];
    $request = ReverbAcceptance::publication(securityPublication());
    expect(ReverbAcceptance::deliver($request))->toBe(200)
        ->and($ownSocket->messages)->toHaveCount(1)
        ->and($otherSocket->messages)->toBe([])
        ->and($publicSocket->messages)->toBe([]);
    $frame = json_decode($ownSocket->messages[0], true, 512, JSON_THROW_ON_ERROR);
    expect($frame['channel'])->toBe('private-team.7')
        ->and($frame['event'])->toBe('CheckCompleted')
        ->and(json_decode($frame['data'], true, 512, JSON_THROW_ON_ERROR)['execution_id'])->toBe(21);
});

it('rejects unsigned body channel and stale timestamp tampering before native delivery', function (string $tamper): void {
    $request = ReverbAcceptance::publication(securityPublication());

    if ($tamper === 'body' || $tamper === 'channel') {
        $data = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $data[$tamper === 'channel' ? 'channel' : 'name'] = $tamper === 'channel' ? 'private-team.8' : 'IncidentResolved';
        $request = $request->withBody(Utils::streamFor(json_encode($data, JSON_THROW_ON_ERROR)));
    } else {
        parse_str($request->getUri()->getQuery(), $query);

        if ($tamper === 'missing-signature') {
            unset($query['auth_signature']);
        } else {
            // Re-sign an expired timestamp so this tests freshness independently of HMAC integrity.
            $query['auth_timestamp'] = time() - 601;
            unset($query['auth_signature']);
            ksort($query);
            $query['auth_signature'] = hash_hmac('sha256', "POST\n".$request->getUri()->getPath()."\n".http_build_query($query), ReverbAcceptance::application()->secret());
        }

        $request = $request->withUri($request->getUri()->withQuery(http_build_query($query)));
    }

    expect(fn () => ReverbAcceptance::deliver($request))->toThrow(HttpException::class, 'Authentication signature invalid.');
})->with(['body', 'channel', 'missing-signature', 'expired-timestamp']);

it('refuses a private subscription without a valid grant through the native event handler', function (): void {
    $connection = ReverbAcceptance::connection(new RecordingReverbSocket(1));
    expect(fn () => app(EventHandler::class)->subscribe($connection, 'private-team.7'))
        ->toThrow(ConnectionUnauthorized::class);
    expect(app(ChannelManager::class)->for($connection->app())->find('private-team.7')?->connections())->toBe([]);
});

it('enforces configured browser origins in the native handshake', function (string $origin, bool $accepted): void {
    $socket = new RecordingReverbSocket(1);
    app(Server::class)->open(ReverbAcceptance::connection($socket, $origin));
    $message = json_decode($socket->messages[0], true, 512, JSON_THROW_ON_ERROR);
    expect($message['event'])->toBe($accepted ? 'pusher:connection_established' : 'pusher:error');

    if (!$accepted) {
        expect(json_decode($message['data'], true, 512, JSON_THROW_ON_ERROR)['message'])->toBe('Origin not allowed');
    }
})->with([
    ['http://localhost:8000', true], ['http://127.0.0.1:8000', true],
    ['https://localhost.attacker.test', false], ['https://foreign.example.test', false], ['null', false], ['', false],
]);

it('rejects browser client events even from a subscribed private channel member', function (): void {
    $senderSocket = new RecordingReverbSocket(1);
    $recipientSocket = new RecordingReverbSocket(2);
    $sender = ReverbAcceptance::connection($senderSocket);
    $recipient = ReverbAcceptance::connection($recipientSocket);
    $handler = app(EventHandler::class);

    foreach ([$sender, $recipient] as $connection) {
        $auth = $connection->app()->key().':'.hash_hmac('sha256', $connection->id().':private-team.7', $connection->app()->secret());
        $handler->subscribe($connection, 'private-team.7', $auth);
    }

    $senderSocket->messages = $recipientSocket->messages = [];
    ClientEvent::handle($sender, ['event' => 'client-CheckCompleted', 'channel' => 'private-team.7', 'data' => ['status' => 'up']]);
    expect($recipientSocket->messages)->toBe([]);
    $frame = json_decode($senderSocket->messages[0], true, 512, JSON_THROW_ON_ERROR);
    expect(json_decode($frame['data'], true, 512, JSON_THROW_ON_ERROR)['code'])->toBe(4301);
});

it('terminates a client that exceeds the configured native message budget without waiting', function (): void {
    $socket = new RecordingReverbSocket(1);
    $connection = ReverbAcceptance::connection($socket);
    $server = app(Server::class);

    for ($sent = 0; $sent < 60; ++$sent) {
        $server->message($connection, '{"event":"pusher:ping","data":{}}');
    }

    expect($socket->closed)->toBeFalse();
    $server->message($connection, '{"event":"pusher:ping","data":{}}');
    expect($socket->closed)->toBeTrue();
    $last = json_decode($socket->messages[array_key_last($socket->messages)], true, 512, JSON_THROW_ON_ERROR);
    expect($last['event'])->toBe('pusher:error');
});
