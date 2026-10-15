<?php

/** Exercise the configured Reverb origin and client-publishing rules without opening sockets. */

use Laravel\Reverb\ConfigApplicationProvider;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\ClientEvent;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;
use Laravel\Reverb\Protocols\Pusher\Server;

it(
    'accepts configured local origins and rejects unrelated hosts',
    /**
     * @param string $origin Browser-supplied origin URL.
     * @param bool $allowed Expected application-level connection authorization.
     */
    function (string $origin, bool $allowed): void {
        /** @var \Laravel\Reverb\Application $application Actual application settings resolved from the versioned Reverb configuration. */
        $application = (new ConfigApplicationProvider(collect(config('reverb.apps.apps'))))->findById('signalbox-test');
        /** @var ChannelManager&\Mockery\MockInterface $channels Empty in-memory connection registry. */
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldReceive('for')->with($application)->andReturnSelf();
        $channels->shouldReceive('connections')->andReturn([]);
        /** @var WebSocketConnection&\Mockery\MockInterface $socket Transport interface with no native network connection. */
        $socket = Mockery::mock(WebSocketConnection::class);
        $socket->shouldReceive('id')->andReturn(1);
        $socket->shouldReceive('send')->once()->with(Mockery::on(
            /** @param string $message Actual Pusher protocol frame returned by the configured Reverb server. */
            fn (string $message): bool => str_contains($message, $allowed ? 'pusher:connection_established' : 'Origin not allowed'),
        ));
        /** @var Connection $connection Real protocol connection wrapping the fake transport. */
        $connection = new Connection($socket, $application, $origin);
        /** @var EventHandler $handler Real Pusher event handler; protocol frames are captured by the fake socket. */
        $handler = new EventHandler($channels);

        (new Server($channels, $handler))->open($connection);
    },
)->with([
    ['http://localhost:8000', true],
    ['http://127.0.0.1:8000', true],
    ['https://attacker.example', false],
    ['http://localhost.attacker.example', false],
]);

it(
    'refuses browser-originated client events',
    /** Prevent a connected browser from impersonating a server-side monitoring event. */
    function (): void {
        /** @var \Laravel\Reverb\Application $application Configured application with client publishing disabled. */
        $application = (new ConfigApplicationProvider(collect(config('reverb.apps.apps'))))->findById('signalbox-test');
        /** @var WebSocketConnection&\Mockery\MockInterface $socket Transport that captures only the expected protocol rejection. */
        $socket = Mockery::mock(WebSocketConnection::class);
        $socket->shouldReceive('send')->once()->with(Mockery::on(
            /** @param string $message Protocol error containing Reverb's disabled-client-messaging code. */
            fn (string $message): bool => str_contains($message, '4301'),
        ));
        /** @var Connection $connection Real browser protocol connection with a fake transport. */
        $connection = new Connection($socket, $application, 'http://localhost:8000');
        ClientEvent::handle($connection, ['event' => 'client-check-completed', 'channel' => 'private-team.1', 'data' => []]);
    },
);

it(
    'publishes a signed event to the configured internal Reverb endpoint',
    /** Capture the real broadcaster's HTTP request without contacting a network service. */
    function (): void {
        /** @var array<int, array<string, mixed>> $history Requests observed by the fake Guzzle transport. */
        $history = [];
        /** @var \GuzzleHttp\Handler\MockHandler $responses Successful empty event-publishing response. */
        $responses = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200, [], '{}')]);
        /** @var \GuzzleHttp\HandlerStack $handler HTTP stack that captures requests without network access. */
        $handler = \GuzzleHttp\HandlerStack::create($responses);
        $handler->push(\GuzzleHttp\Middleware::history($history));
        config([
            'broadcasting.connections.reverb.options.host' => 'reverb',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.client_options.handler' => $handler,
        ]);
        app(\Illuminate\Broadcasting\BroadcastManager::class)->connection('reverb')
            ->broadcast(['bootstrap-probe'], 'bootstrap.ready', ['ready' => true]);
        expect($history)->toHaveCount(1);
        /** @var \Psr\Http\Message\RequestInterface $request Actual signed backend event request. */
        $request = $history[0]['request'];
        expect($request->getUri()->getHost())->toBe('reverb')
            ->and($request->getUri()->getPort())->toBe(8080)
            ->and($request->getUri()->getPath())->toBe('/apps/signalbox-test/events');
        /** @var array<string, string> $query Signature fields generated by the real Pusher-compatible client. */
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        /** @var string $signature Supplied HMAC removed before reconstructing the signed query. */
        $signature = $query['auth_signature'];
        unset($query['auth_signature']);
        ksort($query);
        expect($query['auth_key'])->toBe('public-test-key')
            ->and($query['body_md5'])->toBe(md5((string) $request->getBody()))
            ->and($signature)->toBe(hash_hmac('sha256', "POST\n/apps/signalbox-test/events\n".http_build_query($query), 'fake-test-secret'));
    },
);
