<?php

declare(strict_types=1);

/** Exercise real bearer HTTP calls blocked behind concurrent lifecycle changes on PostgreSQL. */

use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Actions\Monitoring\ManageMonitors;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\IsolatedHttpServer;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and real local HTTP workers.');
    }
});

it('serializes receipt calls against concurrent receipt, rotation, pause and resume operations', function (string $mode): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $server = null;
    $secondServer = null;

    try {
        $monitor = $fixture->committed(fn () => app(ManageMonitors::class)->create($fixture->owner, $fixture->teamId, [
            'name' => 'Concurrent heartbeat', 'type' => 'heartbeat', 'interval_seconds' => 300, 'timeout_ms' => 1000, 'heartbeat_grace_seconds' => 0,
        ]));
        $token = $fixture->committed(fn () => app(ManageHeartbeatCredentials::class)->rotate($fixture->owner, $fixture->teamId, $monitor->id));
        $server = IsolatedHttpServer::start();
        $handler = new CurlMultiHandler();
        $client = $server->client(new CookieJar(), HandlerStack::create($handler));
        $clients = [$client];

        if ($mode === 'receipt') {
            // Separate listeners ensure both requests reach different server processes before either lock is released.
            $secondServer = IsolatedHttpServer::start();
            $clients[] = $secondServer->client(new CookieJar(), HandlerStack::create($handler));
        }
        $pending = [];
        $fixture->connection->beginTransaction();

        try {
            $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
            $pid = (int) $fixture->connection->scalar('SELECT pg_backend_pid()');

            foreach ($clients as $participant) {
                $pending[] = $participant->postAsync('/heartbeats', ['headers' => ['Authorization' => 'Bearer '.$token]]);
            }

            $until = microtime(true) + 5;

            do {
                $handler->tick();
                $fixture->connection->select('SELECT pg_stat_clear_snapshot()');
                // Include indirect waiters: PostgreSQL may queue the second request behind the first waiter.
                $waiting = (int) $fixture->connection->scalar(<<<'SQL'
                    WITH RECURSIVE blocked(pid) AS (
                        SELECT pid FROM pg_stat_activity WHERE ? = ANY(pg_blocking_pids(pid))
                        UNION
                        SELECT activity.pid FROM pg_stat_activity activity
                            JOIN blocked ON blocked.pid = ANY(pg_blocking_pids(activity.pid))
                    ) SELECT count(DISTINCT pid) FROM blocked
                    SQL, [$pid]);

                if ($waiting >= count($pending)) {
                    break;
                }

                usleep(10000);
            } while (microtime(true) < $until);

            expect($waiting)->toBeGreaterThanOrEqual(count($pending));

            if ($mode === 'rotation') {
                $fixture->connection->table('monitor_heartbeats')->where('monitor_id', $monitor->id)->update(['token_hash' => null]);
            } elseif ($mode === 'pause') {
                $fixture->connection->table('monitors')->where('id', $monitor->id)->update(['is_paused' => true]);
            } elseif ($mode === 'resume') {
                // Publish a new arming generation before the waiting public request can record its receipt.
                $fixture->connection->table('monitor_heartbeats')->where('monitor_id', $monitor->id)->update([
                    'generation' => 2, 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP'), 'last_received_at' => null,
                ]);
            }

            $fixture->connection->commit();
        } catch (Throwable $error) {
            $fixture->connection->rollBack();

            throw $error;
        }

        foreach ($pending as $request) {
            $response = $request->wait();
            expect($response->getStatusCode())->toBe($mode === 'rotation' ? 404 : 204)
                ->and($response->hasHeader('Set-Cookie'))->toBeFalse();
        }

        $state = $fixture->connection->table('monitor_heartbeats')->where('monitor_id', $monitor->id)->first();
        $receipts = $fixture->connection->table('heartbeat_receipts')->where('monitor_heartbeat_id', $state->id);

        if (in_array($mode, ['rotation', 'pause'], true)) {
            expect($receipts->count())->toBe(0)->and($state->last_received_at)->toBeNull();
        } else {
            expect($receipts->count())->toBeGreaterThan(0)
                ->and($state->last_received_at)->toBe($receipts->max('received_at'));

            if ($mode === 'resume') {
                expect((int) $receipts->value('generation'))->toBe(2);
            }
        }
    } finally {
        $server?->stop();
        $secondServer?->stop();
        $fixture->cleanup();
    }
})->with(['receipt', 'rotation', 'pause', 'resume']);

it('requires real web CSRF for credential mutations while bearer ingress remains session-free', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $server = null;

    try {
        $monitor = $fixture->committed(fn () => app(ManageMonitors::class)->create($fixture->owner, $fixture->teamId, [
            'name' => 'CSRF heartbeat', 'type' => 'heartbeat', 'interval_seconds' => 300, 'timeout_ms' => 1000, 'heartbeat_grace_seconds' => 0,
        ]));
        $server = IsolatedHttpServer::start();
        $cookies = $server->login($fixture->owner->email, CommittedTeamFixture::PASSWORD);
        $client = $server->client($cookies);
        $path = "/teams/{$fixture->teamId}/monitors/{$monitor->id}/heartbeat-credential";
        expect($client->post($path, ['headers' => ['Accept' => 'application/json']])->getStatusCode())->toBe(419);
        $response = $client->post($path, ['headers' => [
            'Accept' => 'application/json',
            'X-XSRF-TOKEN' => rawurldecode((string) $cookies->getCookieByName('XSRF-TOKEN')?->getValue()),
        ]]);
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Cache-Control'))->toBe('no-store, private');
        $token = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['token'];
        $public = $server->client(new CookieJar())->post('/heartbeats', ['headers' => ['Authorization' => 'Bearer '.$token]]);
        expect($public->getStatusCode())->toBe(204)->and($public->hasHeader('Set-Cookie'))->toBeFalse();
    } finally {
        $server?->stop();
        $fixture->cleanup();
    }
});
