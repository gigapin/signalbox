<?php

declare(strict_types=1);

/** Verify private broadcast payloads, native queue policy and signed Reverb wire requests without external sockets. */

use App\Enums\Monitoring\CheckResultKind;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Events\Broadcasting\TeamUpdate;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Events\Monitoring\CheckCompleted;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\ProvisioningPlan;

/** @return CheckCompleted Minimal regional observation, deliberately using non-UTC input instants. */
function broadcastObservation(): CheckCompleted
{
    return new CheckCompleted(
        7,
        11,
        21,
        MonitorRegion::Eu,
        CarbonImmutable::parse('2026-10-30 11:00:00', 'Europe/Rome'),
        3,
        CheckResultKind::Observed,
        MonitorStatus::Down,
        123,
        CarbonImmutable::parse('2026-10-30 11:00:01', 'Europe/Rome'),
        CarbonImmutable::parse('2026-10-30 11:00:02', 'Europe/Rome'),
    );
}

it('selects only versioned check facts for the committed team private channel', function (): void {
    $event = broadcastObservation();
    $frame = new TeamUpdate($event);
    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($frame)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($frame->broadcastAs())->toBe('CheckCompleted')
        ->and($frame->broadcastOn())->toHaveCount(1)
        ->and($frame->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($frame->broadcastOn()[0]->name)->toBe('private-team.7')
        ->and($frame->broadcastWith())->toBe([
            'schema_version' => 1,
            'monitor_id' => 11,
            'execution_id' => 21,
            'region' => 'eu',
            'slot_at' => '2026-10-30T10:00:00+00:00',
            'monitor_revision' => 3,
            'kind' => 'observed',
            'status' => 'down',
            'response_time_ms' => 123,
            'checked_at' => '2026-10-30T10:00:01+00:00',
            'recorded_at' => '2026-10-30T10:00:02+00:00',
        ]);
});

it('keeps incident messages as private identity-only invalidations', function (string $class, string $name): void {
    $event = new $class(7, 31, 11);
    $frame = new TeamUpdate($event);
    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($frame->broadcastAs())->toBe($name)
        ->and($frame->broadcastOn()[0]->name)->toBe('private-team.7')
        ->and($frame->broadcastWith())->toBe(['schema_version' => 1, 'monitor_id' => 11, 'incident_id' => 31]);
})->with([
    [IncidentOpened::class, 'IncidentOpened'],
    [IncidentReopened::class, 'IncidentReopened'],
    [IncidentResolved::class, 'IncidentResolved'],
]);

it('queues scalar frames on independent Redis broadcasts with bounded transport retries', function (): void {
    Queue::fake()->serializeAndRestore();
    config(['queue.default' => 'sync']);
    app(BroadcastManager::class)->queue(new TeamUpdate(broadcastObservation()));
    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, function (BroadcastEvent $job): bool {
        $options = ProvisioningPlan::get('broadcast-test')->optionsFor('*', 'broadcasts');
        expect($job->tries)->toBe(3)
            ->and($job->timeout)->toBe(10)
            ->and($job->backoff)->toBe([1, 5])
            ->and($options->queue)->toBe('broadcasts')
            ->and($options->connection)->toBe('redis')
            ->and($options->timeout)->toBe($job->timeout)
            ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
            ->and(serialize($job))->not->toContain('ModelIdentifier', 'App\\Events\\Monitoring\\CheckCompleted', 'config_json', 'headers_json');

        return $job->event instanceof TeamUpdate;
    });
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('sends only the selected payload and private channel through the actual Reverb broadcaster', function (): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $handler->push(Middleware::history($history));
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.options.host' => 'reverb',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.client_options.handler' => $handler,
    ]);
    $frame = new TeamUpdate(broadcastObservation());
    (new BroadcastEvent($frame))->handle(app(BroadcastManager::class));
    expect($history)->toHaveCount(1);
    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $data = json_decode($body['data'], true, flags: JSON_THROW_ON_ERROR);
    unset($data['socket']);

    expect($request->getUri()->getHost())->toBe('reverb')
        ->and($request->getUri()->getPath())->toBe('/apps/signalbox-test/events')
        ->and($body['name'])->toBe('CheckCompleted')
        ->and($body['channel'])->toBe('private-team.7')
        ->and($data)->toBe($frame->broadcastWith());
    $query = [];
    parse_str($request->getUri()->getQuery(), $query);
    $signature = $query['auth_signature'];
    unset($query['auth_signature']);
    ksort($query);
    expect($signature)->toBe(hash_hmac('sha256', "POST\n/apps/signalbox-test/events\n".http_build_query($query), 'fake-test-secret'));
});

it('exposes only session-protected POST authorization without a user-signin endpoint', function (): void {
    $this->get('/broadcasting/auth')->assertMethodNotAllowed();
    $this->postJson('/broadcasting/auth')->assertUnauthorized();
    $this->postJson('/broadcasting/user-auth')->assertNotFound();
});

it('selects Redis explicitly even when the application default queue is synchronous', function (): void {
    config(['queue.default' => 'sync']);
    $connection = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
    $connection->shouldReceive('pushOn')->once()->with('broadcasts', Mockery::type(BroadcastEvent::class));
    Queue::shouldReceive('connection')->once()->with('redis')->andReturn($connection);
    app(BroadcastManager::class)->queue(new TeamUpdate(broadcastObservation()));
});
