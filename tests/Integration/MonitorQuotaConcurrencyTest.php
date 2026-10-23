<?php

declare(strict_types=1);

/**
 * Prove through the real HTTP server and Inertia form transport that a sixth Free monitor is refused, both when
 * the request must wait for a competing creation's Team lock and when several submissions race in parallel.
 */

use App\Actions\Monitoring\ManageMonitors;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\IsolatedHttpServer;
use Tests\Support\MonitorInputFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
});

/** @var string QUOTA_MESSAGE Free plan refusal shown in the form's general alert. */
const QUOTA_MESSAGE = 'Your plan allows 5 monitors. Delete a monitor or upgrade to add another.';

/**
 * Poll until another backend of the test database waits on a row lock, ticking the HTTP transport meanwhile so
 * the pending request is actually sent. The bounded deadline turns a missing wait into a failure, not a hang.
 *
 * @param ConnectionInterface $connection Connection that may observe pg_stat_activity.
 * @param CurlMultiHandler $transport Handler carrying the pending request.
 * @throws RuntimeException When no lock waiter appears within five seconds.
 */
function awaitLockWaiter(ConnectionInterface $connection, CurlMultiHandler $transport): void
{
    $deadline = microtime(true) + 5;

    while (microtime(true) < $deadline) {
        $transport->tick();

        // Statistics views are snapshotted per transaction, and this connection holds one open.
        $connection->select('select pg_stat_clear_snapshot()');

        $waiting = $connection->selectOne(
            "select count(*) as waiting from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()",
        );

        if ($waiting->waiting > 0) {
            return;
        }

        usleep(10_000);
    }

    throw new RuntimeException('The HTTP creation never waited for the Team lock.');
}

/**
 * @param IsolatedHttpServer $server Running server.
 * @param CookieJar $cookies Session that received the redirect and its flashed errors.
 * @param string $path Page to visit, as the browser would after the redirect.
 * @return array<string, mixed> Inertia page object of that visit.
 */
function visitPage(IsolatedHttpServer $server, CookieJar $cookies, string $path): array
{
    $response = $server->client($cookies)->get($path, ['headers' => $server->inertiaHeaders($cookies)]);

    expect($response->getStatusCode())->toBe(200);

    return IsolatedHttpServer::page($response);
}

/**
 * @param ResponseInterface $response Redirect returned by the creation route.
 * @return string Location path, without scheme and host.
 */
function redirectPath(ResponseInterface $response): string
{
    return (string) parse_url($response->getHeaderLine('Location'), PHP_URL_PATH);
}

it('makes a sixth UI creation wait for a competing fifth creation and then refuses it', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(4);
    $server = null;

    try {
        $server = IsolatedHttpServer::start(workers: 2);
        $cookies = $server->login($fixture->owner->email, CommittedTeamFixture::PASSWORD);
        $form = "/teams/{$fixture->teamId}/monitors/create";
        $transport = new CurlMultiHandler();
        $client = $server->client($cookies, HandlerStack::create($transport));

        $fixture->connection->beginTransaction();

        try {
            // The fifth creation holds the Team lock in this uncommitted probe transaction.
            $fixture->committed(fn () => app(ManageMonitors::class)->create($fixture->owner, $fixture->teamId, MonitorInputFixture::http()));

            $pending = $client->postAsync("/teams/{$fixture->teamId}/monitors", [
                'headers' => $server->inertiaHeaders($cookies, $form),
                'json' => [...MonitorInputFixture::http(), 'name' => 'Sixth monitor'],
            ]);

            awaitLockWaiter($fixture->connection, $transport);

            // Other sessions still see four monitors: the sixth has not been written past the passed Form Request.
            expect(DB::connection('pgsql')->table('monitors')->where('team_id', $fixture->teamId)->count())->toBe(4);

            $fixture->connection->commit();
        } catch (Throwable $exception) {
            $fixture->connection->rollBack();

            throw $exception;
        }

        $response = $pending->wait();

        expect($response->getStatusCode())->toBe(302)
            ->and(redirectPath($response))->toBe($form);

        $page = visitPage($server, $cookies, $form);

        expect($page['component'])->toBe('Monitors/Create')
            ->and($page['props']['errors'])->toBe(['monitor' => QUOTA_MESSAGE])
            ->and(json_encode($page, JSON_THROW_ON_ERROR))->not->toContain('synthetic-secret')
            ->and($fixture->monitorCount())->toBe(5);
    } catch (Throwable $exception) {
        throw new RuntimeException('UI quota contention failed. Server output: '.$server?->diagnostics(), previous: $exception);
    } finally {
        $server?->stop();
        $fixture->cleanup();
    }
});

it('accepts exactly one of several parallel UI submissions for the last Free slot', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(4);
    $server = null;

    try {
        $server = IsolatedHttpServer::start(workers: 4);
        $session = $server->login($fixture->owner->email, CommittedTeamFixture::PASSWORD);
        $form = "/teams/{$fixture->teamId}/monitors/create";
        $client = $server->client($session, HandlerStack::create(new CurlMultiHandler()));
        $jars = [];
        $promises = [];

        // One shared transport sends every request at once; each tab keeps its own copy of the session.
        foreach (range(1, 4) as $tab) {
            $jars[$tab] = new CookieJar(false, $session->toArray());
            $promises[$tab] = $client->postAsync("/teams/{$fixture->teamId}/monitors", [
                'cookies' => $jars[$tab],
                'headers' => $server->inertiaHeaders($jars[$tab], $form),
                'json' => [...MonitorInputFixture::http(), 'name' => "Tab {$tab}"],
            ]);
        }

        $responses = Utils::all($promises)->wait();
        $created = [];
        $refused = [];

        foreach ($responses as $tab => $response) {
            expect($response->getStatusCode())->toBe(302)
                ->and((string) $response->getBody())->not->toContain('synthetic-secret');

            if (redirectPath($response) === $form) {
                $refused[] = $tab;
            } else {
                $created[$tab] = redirectPath($response);
            }
        }

        expect($created)->toHaveCount(1)
            ->and($refused)->toHaveCount(3)
            ->and($fixture->monitorCount())->toBe(5);

        $newId = $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->max('id');

        expect(array_values($created))->toBe(["/teams/{$fixture->teamId}/monitors/{$newId}"])
            ->and($fixture->connection->table('monitors')->where('id', $newId)->value('name'))->toBe('Tab '.array_key_first($created));

        foreach ($refused as $tab) {
            expect(visitPage($server, $jars[$tab], $form)['props']['errors'])->toBe(['monitor' => QUOTA_MESSAGE]);
        }
    } catch (Throwable $exception) {
        throw new RuntimeException('Parallel UI quota submissions failed. Server output: '.$server?->diagnostics(), previous: $exception);
    } finally {
        $server?->stop();
        $fixture->cleanup();
    }
});
