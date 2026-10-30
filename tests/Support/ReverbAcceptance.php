<?php

declare(strict_types=1);

/** Resolve the installed Reverb protocol with isolated channel storage and capture native publisher requests. */

namespace Tests\Support;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Laravel\Reverb\Application;
use Laravel\Reverb\ConfigApplicationProvider;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\Http\Controllers\EventsController;
use Laravel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Laravel\Reverb\Protocols\Pusher\Managers\ArrayChannelManager;
use Laravel\Reverb\Servers\Reverb\Http\Connection as HttpConnection;
use Mockery;
use Psr\Http\Message\RequestInterface;

/** Use native validation/routing with fake HTTP/socket I/O; no broker or listener is opened. */
final class ReverbAcceptance
{
    /** Bind fresh native channel registries; per-channel connection managers must remain transient. */
    public static function isolate(): void
    {
        app()->singleton(ChannelManager::class, ArrayChannelManager::class);
        app()->bind(ChannelConnectionManager::class, ArrayChannelConnectionManager::class);
    }

    /** @return Application Actual configured test application, including origin/client-event restrictions. */
    public static function application(): Application
    {
        return (new ConfigApplicationProvider(collect(config('reverb.apps.apps'))))->findById('signalbox-test');
    }

    /**
     * @param RecordingReverbSocket $socket Transport recorder for native delivery assertions.
     * @param string $origin Synthetic browser origin, including untrusted origins in negative cases.
     * @return Connection Real normalized socket identity and application-bound connection.
     */
    public static function connection(RecordingReverbSocket $socket, string $origin = 'http://localhost:8000'): Connection
    {
        return new Connection($socket, self::application(), $origin);
    }

    /**
     * @param BroadcastEvent $job Real queued scalar frame to deliver through the installed Pusher SDK.
     * @return RequestInterface Signed server-publish HTTP request captured without network access.
     */
    public static function publication(BroadcastEvent $job): RequestInterface
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $handler->push(Middleware::history($history));
        config(['broadcasting.connections.reverb.client_options.handler' => $handler]);
        $manager = app(BroadcastManager::class);
        $manager->purge('reverb');
        $job->handle($manager);

        return $history[0]['request'];
    }

    /**
     * @param RequestInterface $request Captured or deliberately tampered native publish request.
     * @return int Actual Reverb controller response status after native signature/timestamp validation and channel delivery.
     */
    public static function deliver(RequestInterface $request): int
    {
        $request->getBody()->rewind();
        // The controller does not use this HTTP response transport; signature verification and dispatch stay native.
        $transport = Mockery::mock(HttpConnection::class);
        $response = app(EventsController::class)($request, $transport, 'signalbox-test');

        return $response->getStatusCode();
    }
}
