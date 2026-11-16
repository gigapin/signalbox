<?php

declare(strict_types=1);

/** Exercise public consent and heartbeat contention with independently committed PostgreSQL participants. */

use App\Actions\Incidents\ManageIncidents;
use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Actions\Monitoring\ManageMonitors;
use App\Actions\StatusPages\ManageStatusPages;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Services\StatusPages\StatusEmailLedger;
use App\Services\StatusPages\StatusSubscriptionLinks;
use App\Services\StatusPages\StatusSubscriptions;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CommittedTeamFixture;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL row locks on independent connections.');
    }

    $this->withoutVite();
    config(['app.debug' => false]);
    Cache::flush();
    Queue::fake();
    $this->fixture = CommittedTeamFixture::freeTeam(0);
    $this->targets = publicIngressTargets($this->fixture);
    config(['database.connections.public_ingress_receiver' => config('database.connections.pgsql')]);
    // Bound even a deliberately restored legacy implementation, so regression probes cannot hang indefinitely.
    DB::connection('public_ingress_receiver')->statement("SET lock_timeout = '1s'");
});

afterEach(function (): void {
    DB::purge('public_ingress_receiver');

    if (isset($this->fixture)) {
        while ($this->fixture->connection->transactionLevel() > 0) {
            $this->fixture->connection->rollBack();
        }

        $this->fixture->cleanup();
    }
});

/**
 * @param CommittedTeamFixture $fixture Isolated committed tenant, always cleaned up by the caller.
 * @return array{monitors: list<Monitor>, tokens: list<string>, pages: list<StatusPage>, subscriber: StatusPageSubscriber}
 *         Two independent heartbeat/page targets and one pending consent on the first page.
 */
function publicIngressTargets(CommittedTeamFixture $fixture): array
{
    return $fixture->committed(function () use ($fixture): array {
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->update(['plan' => 'pro']);
        $monitors = [];
        $tokens = [];
        $pages = [];

        foreach (['a', 'b'] as $suffix) {
            $monitor = app(ManageMonitors::class)->create($fixture->owner, $fixture->teamId, [
                'name' => 'Ingress heartbeat '.$suffix,
                'type' => 'heartbeat',
                'interval_seconds' => 300,
                'timeout_ms' => 1000,
                'heartbeat_grace_seconds' => 0,
            ]);
            $monitors[] = $monitor;
            $tokens[] = app(ManageHeartbeatCredentials::class)->rotate($fixture->owner, $fixture->teamId, $monitor->id);
            $pages[] = app(ManageStatusPages::class)->create($fixture->owner, $fixture->teamId, new StatusPageInput(
                'ingress-'.$fixture->teamId.'-'.$suffix,
                'Public ingress fixture',
                null,
                new StatusPageTheme(StatusPageThemeMode::System, StatusPageAccent::Emerald),
                true,
                null,
                false,
                [['name' => 'Public API', 'monitor_ids' => [$monitor->id]]],
            ));
        }

        app(StatusSubscriptions::class)->subscribe($pages[0], app('session.store'), 'reader@example.test');
        $subscriber = app(TenantContext::class)->run($fixture->teamId, fn (): StatusPageSubscriber => StatusPageSubscriber::query()
            ->where('status_page_id', $pages[0]->id)->firstOrFail());

        return compact('monitors', 'tokens', 'pages', 'subscriber');
    });
}

/**
 * @template TResult
 * @param Closure(): TResult $operation Public request or internal participant outside RefreshDatabase's outer transaction.
 * @return TResult Operation result with the previous default database connection restored.
 */
function publicIngressProbe(Closure $operation): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('public_ingress_receiver');

    try {
        return $operation();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * @param CommittedTeamFixture $fixture Owner and independently committed domain storage.
 * @param int $monitorId Selected monitor on the first public page.
 * @param bool $publish Whether to commit the explicit public transition before returning.
 * @return int Incident update identity, with an update delivery only when publication is requested.
 */
function publicIngressUpdate(CommittedTeamFixture $fixture, int $monitorId, bool $publish): int
{
    return $fixture->committed(fn (): int => app(TenantContext::class)->run($fixture->teamId, function () use ($fixture, $monitorId, $publish): int {
        $incident = Incident::factory()->create(['monitor_id' => $monitorId]);
        $entry = app(ManageIncidents::class)->postUpdate(
            $fixture->owner,
            $fixture->teamId,
            $incident->id,
            IncidentStatus::Investigating,
            'Public synthetic update',
        );

        if ($publish) {
            app(ManageIncidents::class)->publish($fixture->owner, $fixture->teamId, $entry->id);
        }

        return $entry->id;
    }));
}

it('accepts subscription and consent actions without waiting for an unrelated exclusive team lock', function (string $operation): void {
    $page = $this->targets['pages'][0];
    $subscriber = $this->targets['subscriber'];
    $this->fixture->connection->beginTransaction();
    $this->fixture->connection->table('teams')->where('id', $this->fixture->teamId)->lockForUpdate()->first();

    if ($operation === 'subscribe') {
        publicIngressProbe(fn () => $this->postJson('/status/'.$page->slug.'/subscribe', ['email' => 'another@example.test']))->assertRedirect();
        expect($this->fixture->connection->table('status_page_subscribers')->where('status_page_id', $page->id)->count())->toBe(2);
    } else {
        $url = app(StatusSubscriptionLinks::class)->action($subscriber, $operation);
        publicIngressProbe(fn () => $this->postJson($url))->assertOk();
        $column = $operation === 'verify' ? 'verified_at' : 'unsubscribed_at';
        expect($this->fixture->connection->table('status_page_subscribers')->where('id', $subscriber->id)->value($column))->not->toBeNull();
    }
})->with(['subscribe', 'verify', 'unsubscribe']);

it('does not let a busy page serialize subscription requests for another page of the same team', function (): void {
    [$busy, $other] = $this->targets['pages'];
    $this->fixture->connection->beginTransaction();
    $this->fixture->connection->table('status_pages')->where('id', $busy->id)->lockForUpdate()->first();
    publicIngressProbe(fn () => $this->postJson('/status/'.$other->slug.'/subscribe', ['email' => 'other-page@example.test']))->assertRedirect();
    expect($this->fixture->connection->table('status_page_subscribers')->where('status_page_id', $other->id)->count())->toBe(1);
});

it('keeps scanner GETs inert and free of row locks while page and recipient writes are in flight', function (string $action): void {
    $page = $this->targets['pages'][0];
    $subscriber = $this->targets['subscriber'];
    $url = app(StatusSubscriptionLinks::class)->action($subscriber, $action);
    $this->fixture->connection->beginTransaction();
    $this->fixture->connection->table('status_pages')->where('id', $page->id)->lockForUpdate()->first();
    $this->fixture->connection->table('status_page_subscribers')->where('id', $subscriber->id)->lockForUpdate()->first();
    publicIngressProbe(fn () => $this->get($url))->assertOk()->assertDontSee('reader@example.test', false);
    $row = $this->fixture->connection->table('status_page_subscribers')->where('id', $subscriber->id)->first();
    expect($row->verified_at)->toBeNull()->and($row->unsubscribed_at)->toBeNull();
})->with(['verify', 'unsubscribe']);

it('returns a private retryable refusal without partial consent and succeeds after the page becomes free', function (string $operation): void {
    $page = $this->targets['pages'][0];
    $subscriber = $this->targets['subscriber'];
    $url = $operation === 'subscribe'
        ? '/status/'.$page->slug.'/subscribe'
        : app(StatusSubscriptionLinks::class)->action($subscriber, $operation);
    $payload = $operation === 'subscribe' ? ['email' => 'blocked-reader@example.test'] : [];
    $this->fixture->connection->beginTransaction();
    $this->fixture->connection->table('status_pages')->where('id', $page->id)->lockForUpdate()->first();

    app(TenantContext::class)->run(987654321, function () use ($url, $payload): void {
        publicIngressProbe(fn () => $this->postJson($url, $payload))
            ->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => 'The service is busy. Please try again shortly.']);
        expect(app(TenantContext::class)->requireTeamId())->toBe(987654321);
    });
    $row = $this->fixture->connection->table('status_page_subscribers')->where('id', $subscriber->id)->first();
    expect($row->verified_at)->toBeNull()->and($row->unsubscribed_at)->toBeNull()
        ->and($this->fixture->connection->table('status_page_subscribers')->where('team_id', $this->fixture->teamId)->count())->toBe(1)
        ->and($this->fixture->connection->table('status_page_deliveries')->where('team_id', $this->fixture->teamId)->count())->toBe(1);
    $this->fixture->connection->rollBack();
    $retry = publicIngressProbe(fn () => $this->postJson($url, $payload));

    if ($operation === 'subscribe') {
        $retry->assertRedirect();
    } else {
        $retry->assertOk();
    }
})->with(['subscribe', 'verify', 'unsubscribe']);

it('accepts heartbeats under a shared team admission lock and independently of another monitor', function (): void {
    $this->fixture->connection->beginTransaction();
    $this->fixture->connection->table('teams')->where('id', $this->fixture->teamId)->sharedLock()->first();
    $this->fixture->connection->table('monitors')->where('id', $this->targets['monitors'][0]->id)->lockForUpdate()->first();
    $response = publicIngressProbe(fn () => $this->postJson('/heartbeats', [], [
        'Authorization' => 'Bearer '.$this->targets['tokens'][1],
    ]));
    $response->assertNoContent()->assertHeaderMissing('Set-Cookie');
    expect($this->fixture->connection->table('heartbeat_receipts')->where('team_id', $this->fixture->teamId)->count())->toBe(1);
});

it('bounds team monitor and state contention without recording a heartbeat or hiding a credential change', function (string $locked): void {
    $monitor = $this->targets['monitors'][0];
    $token = $this->targets['tokens'][0];

    if ($locked === 'team') {
        // A pending downgrade must exclude a cadence that was valid only on the prior Pro entitlement.
        $this->fixture->committed(fn () => app(ManageMonitors::class)->update(
            $this->fixture->owner,
            $this->fixture->teamId,
            $monitor->id,
            ['interval_seconds' => 60],
        ));
    }

    $this->fixture->connection->beginTransaction();

    if ($locked === 'team') {
        $this->fixture->connection->table('teams')->where('id', $this->fixture->teamId)->lockForUpdate()->first();
    } elseif ($locked === 'monitor') {
        $this->fixture->connection->table('monitors')->where('id', $monitor->id)->lockForUpdate()->first();
    } else {
        $this->fixture->connection->table('monitor_heartbeats')->where('monitor_id', $monitor->id)->lockForUpdate()->first();
    }

    publicIngressProbe(fn () => $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token]))
        ->assertStatus(503)->assertHeader('Retry-After', '1')->assertHeaderMissing('Set-Cookie')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['message' => 'The service is busy. Please try again shortly.']);
    expect($this->fixture->connection->table('heartbeat_receipts')->where('team_id', $this->fixture->teamId)->count())->toBe(0);

    if ($locked === 'state') {
        $this->fixture->connection->table('monitor_heartbeats')->where('monitor_id', $monitor->id)->update(['token_hash' => null]);
        $this->fixture->connection->commit();
    } elseif ($locked === 'team') {
        $this->fixture->connection->table('teams')->where('id', $this->fixture->teamId)->update(['plan' => 'free']);
        $this->fixture->connection->commit();
    } else {
        $this->fixture->connection->rollBack();
    }

    $retry = publicIngressProbe(fn () => $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token]));
    $retry->assertStatus($locked === 'state' ? 404 : 204);
    expect($this->fixture->connection->table('heartbeat_receipts')->where('team_id', $this->fixture->teamId)->count())
        ->toBe($locked === 'monitor' ? 1 : 0);
})->with(['team', 'monitor', 'state']);

it('rolls back consent when recipient or outbox locks become busy after the page was acquired', function (string $locked): void {
    $subscriber = $this->targets['subscriber'];
    $action = $locked === 'delivery' ? 'unsubscribe' : 'verify';
    $url = app(StatusSubscriptionLinks::class)->action($subscriber, $action);
    $this->fixture->connection->beginTransaction();

    if ($locked === 'delivery') {
        $this->fixture->connection->table('status_page_deliveries')->where('subscriber_id', $subscriber->id)->lockForUpdate()->first();
    } else {
        $this->fixture->connection->table('status_page_subscribers')->where('id', $subscriber->id)->lockForUpdate()->first();
    }

    publicIngressProbe(fn () => $this->postJson($url))->assertStatus(503)->assertHeader('Retry-After', '1');
    $row = $this->fixture->connection->table('status_page_subscribers')->where('id', $subscriber->id)->first();
    expect($row->verified_at)->toBeNull()->and($row->unsubscribed_at)->toBeNull()
        ->and($this->fixture->connection->table('status_page_deliveries')->where('subscriber_id', $subscriber->id)->value('status'))->toBe('pending');
    $this->fixture->connection->rollBack();
    publicIngressProbe(fn () => $this->postJson($url))->assertOk();
})->with(['subscriber', 'delivery']);

it('orders publication snapshots after consent using the page lock rather than an anonymous team lock', function (): void {
    $page = $this->targets['pages'][0];
    $subscriber = $this->targets['subscriber'];
    $entryId = publicIngressUpdate($this->fixture, $this->targets['monitors'][0]->id, publish: false);
    $this->fixture->connection->beginTransaction();
    $this->fixture->committed(fn () => app(StatusSubscriptions::class)->act(
        $this->fixture->teamId,
        $page->id,
        $subscriber->id,
        $subscriber->generation,
        'verify',
        true,
    ));
    DB::connection('public_ingress_receiver')->statement("SET lock_timeout = '50ms'");

    try {
        publicIngressProbe(fn () => app(ManageIncidents::class)->publish($this->fixture->owner, $this->fixture->teamId, $entryId));
        $this->fail('Publication must not overtake uncommitted page consent.');
    } catch (QueryException $error) {
        expect($error->errorInfo[0])->toBe('55P03');
    }

    expect($this->fixture->connection->table('incident_updates')->where('id', $entryId)->value('is_public'))->toBeFalse();
    $this->fixture->connection->commit();
    publicIngressProbe(fn () => app(ManageIncidents::class)->publish($this->fixture->owner, $this->fixture->teamId, $entryId));
    expect($this->fixture->connection->table('status_page_deliveries')->where('incident_update_id', $entryId)->count())->toBe(1);
});

it('coordinates consent revocation with delivery admission before a provider attempt is leased', function (): void {
    $page = $this->targets['pages'][0];
    $subscriber = $this->targets['subscriber'];
    $this->fixture->committed(fn () => app(StatusSubscriptions::class)->act(
        $this->fixture->teamId,
        $page->id,
        $subscriber->id,
        $subscriber->generation,
        'verify',
        true,
    ));
    $entryId = publicIngressUpdate($this->fixture, $this->targets['monitors'][0]->id, publish: true);
    $deliveryId = $this->fixture->connection->table('status_page_deliveries')->where('incident_update_id', $entryId)->value('id');
    expect($this->fixture->connection->table('status_page_deliveries')->where('id', $deliveryId)->value('status'))->toBe('pending');
    $this->fixture->connection->beginTransaction();
    $this->fixture->committed(fn () => app(StatusSubscriptions::class)->act(
        $this->fixture->teamId,
        $page->id,
        $subscriber->id,
        $subscriber->generation,
        'unsubscribe',
        true,
    ));
    DB::connection('public_ingress_receiver')->statement("SET lock_timeout = '50ms'");
    $token = (string) Str::uuid();

    try {
        publicIngressProbe(fn () => app(StatusEmailLedger::class)->acquire($this->fixture->teamId, (int) $deliveryId, $token));
        $this->fail('Delivery must not overtake uncommitted consent revocation.');
    } catch (QueryException $error) {
        expect($error->errorInfo[0])->toBe('55P03');
    }

    $this->fixture->connection->commit();
    expect(publicIngressProbe(fn () => app(StatusEmailLedger::class)->acquire($this->fixture->teamId, (int) $deliveryId, $token)))->toBeNull()
        ->and($this->fixture->connection->table('status_page_deliveries')->where('id', $deliveryId)->value('attempts'))->toBe(0);
});
