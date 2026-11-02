<?php

declare(strict_types=1);

/** Verify ownership leases, tenant authorization, restricted hosts and custom-origin session isolation on PostgreSQL. */

use App\Actions\StatusPages\ManageStatusDomains;
use App\Contracts\Monitoring\DnsTransport;
use App\Enums\Monitoring\CheckError;
use App\Exceptions\CheckTransportFailed;
use App\Exceptions\MissingTenantContext;
use App\Exceptions\StatusPageRejected;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusDomainRegistry;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\FakeDnsTransport;
use Tests\Support\TestDatabaseGuard;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL and Redis.');
    }

    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    Cache::flush();
    $this->dns = new FakeDnsTransport();
    $this->app->instance(DnsTransport::class, $this->dns);
    $this->team = Team::factory()->create();
    $this->page = app(TenantContext::class)->run($this->team->id, fn (): StatusPage => StatusPage::factory()->create([
        'slug' => 'domain-page', 'title' => 'Public domain page', 'is_public' => true,
    ]));
});

/**
 * @param StatusPage|null $page Claim owner; defaults to the current fixture.
 * @param Team|null $team Owning team; defaults to the current fixture.
 * @param string $hostname Canonical customer name.
 * @return StatusPage Saved pending claim with its public proof generation.
 */
function statusDomainClaim(?StatusPage $page = null, ?Team $team = null, string $hostname = 'status.example.com'): StatusPage
{
    $page ??= test()->page;
    $team ??= test()->team;
    app(ManageStatusDomains::class)->save($team->owner, $team->id, $page->id, $hostname);

    return app(TenantContext::class)->run($team->id, fn (): StatusPage => $page->fresh());
}

/**
 * @param StatusPage $page Pending claim with its exact current generation.
 * @param list<string>|null $values Exact TXT values; null supplies the matching proof.
 */
function statusDomainDns(StatusPage $page, ?array $values = null): void
{
    $name = '_signalbox.'.$page->custom_domain.'.';
    $values ??= ['signalbox-verification='.$page->domain_challenge];
    $records = array_map(static fn (string $value): Record => new Record($name, Message::TYPE_TXT, Message::CLASS_IN, 60, [$value]), $values);
    test()->dns->answers[$name.'|'.Message::TYPE_TXT] = Message::createResponseWithAnswersForQuery(new Query($name, Message::TYPE_TXT, Message::CLASS_IN), $records);
}

/** @return StatusPage A currently verified claim for the current fixture. */
function verifiedStatusDomain(): StatusPage
{
    $page = statusDomainClaim();
    statusDomainDns($page);
    expect(app(ManageStatusDomains::class)->verify(test()->team->owner, test()->team->id, $page->id))->toBeTrue();

    return $page;
}

it('normalizes IDNA and case without querying DNS when a claim is saved', function (): void {
    $page = statusDomainClaim(hostname: 'STATUS.BÜCHER.COM.');
    expect($page->custom_domain)->toBe('status.xn--bcher-kva.com')
        ->and(Str::isUuid($page->domain_challenge))->toBeTrue()
        ->and($this->dns->queries)->toBeEmpty()
        ->and(DB::table('status_domain_bindings')->count())->toBe(0);
    expect($page->toArray())->not->toHaveKey('domain_challenge');
});

it('refuses nonpublic URL wildcard and platform hostname inputs', function (string $hostname): void {
    config(['app.url' => 'https://app.signalbox.com']);
    expect(fn () => app(ManageStatusDomains::class)->save($this->team->owner, $this->team->id, $this->page->id, $hostname))->toThrow(StatusPageRejected::class);
    expect($this->dns->queries)->toBeEmpty()->and(DB::table('status_domain_bindings')->count())->toBe(0);
})->with([
    'https://status.example.com', 'status.example.com/path', 'status.example.com:443', '*.example.com',
    '127.0.0.1', '2130706433', '127.1', '[::1]', 'localhost', 'status.localhost', 'status.internal',
    'status.test', 'status.invalid', 'singlelabel', 'app.signalbox.com', 'status.app.signalbox.com', 'a..example.com',
]);

it('returns no page for unknown unverified or noncanonical hosts before starting a session', function (string $host): void {
    statusDomainClaim();
    $response = $this->get('https://'.$host.'/status/domain-page')->assertNotFound();
    expect($response->headers->getCookies())->toBeEmpty();
})->with(['status.example.com', 'unknown.example.com', 'status.example.com.']);

it('activates exact TXT proof and serves only the page at the verified host', function (): void {
    $page = verifiedStatusDomain();
    $this->get('https://status.example.com/')->assertRedirect('https://status.example.com/status/domain-page');
    $this->get('https://status.example.com/status/domain-page')->assertOk()
        ->assertInertia(fn (AssertableInertia $view) => $view->component('StatusPages/Public')
            ->where('page.title', 'Public domain page')->where('auth.user', null)->where('realtime', null))
        ->assertDontSee($page->domain_challenge, false);
    $binding = DB::table('status_domain_bindings')->first();
    expect($binding->team_id)->toBe($this->team->id)->and($binding->status_page_id)->toBe($this->page->id)
        ->and(CarbonImmutable::parse($binding->expires_at)->diffInHours(CarbonImmutable::now(), absolute: true))->toBe(24.0);
});

it('denies account private cross-page capability and health routes on a verified custom host', function (string $path, string $method): void {
    verifiedStatusDomain();
    $this->actingAs($this->team->owner)->call($method, 'https://status.example.com'.$path)->assertNotFound();
})->with([
    ['/login', 'GET'], ['/account', 'GET'], ['/teams', 'GET'], ['/up', 'GET'],
    ['/teams/1/status-pages', 'GET'], ['/broadcasting/auth', 'POST'], ['/status/other-page', 'GET'],
    ['/status/domain-page', 'DELETE'], ['/status/subscriptions/1/2/3/abc/verify', 'GET'],
]);

it('does not project primary authentication or touch its existing session and configuration', function (): void {
    verifiedStatusDomain();
    $this->actingAs($this->team->owner)->withSession(['private_marker' => 'PRIMARY PRIVATE STATE']);
    $session = app('session.store');
    $configuration = config('session');
    $response = $this->get('https://status.example.com/status/domain-page')->assertOk();
    $response->assertDontSee($this->team->owner->email, false)->assertDontSee('PRIMARY PRIVATE STATE', false);
    expect(app('session.store'))->toBe($session)->and(config('session'))->toBe($configuration)
        ->and($session->get('private_marker'))->toBe('PRIMARY PRIVATE STATE');
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'signalbox_status_'));
    expect($cookie)->not->toBeNull()->and($cookie->getDomain())->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('keeps password unlock and subscriptions working with an independent Redis session cookie', function (): void {
    config(['session.driver' => 'redis']);
    verifiedStatusDomain();
    DB::table('status_pages')->where('id', $this->page->id)->update(['password_hash' => bcrypt('correct-password')]);
    $initial = $this->get('https://status.example.com/status/domain-page')->assertInertia(fn (AssertableInertia $view) => $view->component('StatusPages/Unlock'));
    $cookie = collect($initial->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'signalbox_status_'));
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    $unlocked = $this->post('https://status.example.com/status/domain-page/unlock', ['password' => 'correct-password'])->assertRedirect();
    $cookie = collect($unlocked->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'signalbox_status_'));
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    $this->get('https://status.example.com/status/domain-page')->assertInertia(fn (AssertableInertia $view) => $view->component('StatusPages/Public'));
    $this->post('https://status.example.com/status/domain-page/subscribe', ['email' => 'reader@example.test'])->assertRedirect();
    expect(DB::table('status_page_subscribers')->count())->toBe(1);
    $this->get('http://localhost/status/domain-page')->assertInertia(fn (AssertableInertia $view) => $view->component('StatusPages/Unlock'));
});

it('immediately revokes routing after removal password-independent draft and deletion changes', function (string $change): void {
    verifiedStatusDomain();
    match ($change) {
        'remove' => app(ManageStatusDomains::class)->save($this->team->owner, $this->team->id, $this->page->id, null),
        'replace' => statusDomainClaim(hostname: 'replacement.example.com'),
        'draft' => DB::table('status_pages')->where('id', $this->page->id)->update(['is_public' => false]),
        'delete' => app(TenantContext::class)->run($this->team->id, fn () => $this->page->delete()),
    };
    $this->get('https://status.example.com/status/domain-page')->assertNotFound();
})->with(['remove', 'replace', 'draft', 'delete']);

it('expires routing at the exact lease boundary without depending on the scheduler', function (): void {
    verifiedStatusDomain();
    $this->travel(86399)->seconds();
    expect(app(StatusDomainRegistry::class)->publicPage('status.example.com'))->not->toBeNull();
    $this->travel(1)->seconds();
    expect(app(StatusDomainRegistry::class)->publicPage('status.example.com'))->toBeNull();
    $this->get('https://status.example.com/status/domain-page')->assertNotFound();
});

it('renews valid ownership and revokes it on changed absent malformed or timed-out proof', function (string $failure): void {
    $page = verifiedStatusDomain();
    $this->travel(1)->hours();
    expect(app(ManageStatusDomains::class)->verify($this->team->owner, $this->team->id, $page->id))->toBeTrue();
    $name = '_signalbox.status.example.com.';
    if ($failure === 'timeout') {
        $this->dns->answers[$name.'|'.Message::TYPE_TXT] = new CheckTransportFailed(CheckError::Timeout);
    } elseif ($failure === 'malformed') {
        $this->dns->answers[$name.'|'.Message::TYPE_TXT] = new Message();
    } else {
        statusDomainDns($page, $failure === 'absent' ? [] : ['signalbox-verification=wrong-proof']);
    }
    expect(app(ManageStatusDomains::class)->verify($this->team->owner, $this->team->id, $page->id))->toBeFalse()
        ->and(DB::table('status_domain_bindings')->count())->toBe(0);
})->with(['wrong', 'absent', 'timeout', 'malformed']);

it('allows pending duplicate claims but rejects takeover until the current verified lease expires', function (): void {
    $first = verifiedStatusDomain();
    $other = Team::factory()->create();
    $page = app(TenantContext::class)->run($other->id, fn (): StatusPage => StatusPage::factory()->create(['is_public' => true]));
    $second = statusDomainClaim($page, $other);
    statusDomainDns($second);
    expect(app(ManageStatusDomains::class)->verify($other->owner, $other->id, $page->id))->toBeFalse();
    expect(app(StatusDomainRegistry::class)->publicPage('status.example.com')->id)->toBe($first->id);
    $this->travel(24)->hours();
    expect(app(ManageStatusDomains::class)->verify($other->owner, $other->id, $page->id))->toBeTrue()
        ->and(app(StatusDomainRegistry::class)->publicPage('status.example.com')->id)->toBe($page->id);
});

it('fences a DNS result after claim rotation and rechecks actor authority after network I/O', function (string $change): void {
    $page = statusDomainClaim();
    statusDomainDns($page);
    $actor = User::factory()->create();
    $this->team->users()->attach($actor->id, ['role' => 'admin']);
    $this->dns->beforeReply = function () use ($change, $actor): void {
        if ($change === 'claim') {
            statusDomainClaim(hostname: 'new.example.com');
        } else {
            $this->team->users()->detach($actor->id);
        }
    };
    if ($change === 'claim') {
        expect(app(ManageStatusDomains::class)->verify($actor, $this->team->id, $page->id))->toBeFalse();
    } else {
        expect(fn () => app(ManageStatusDomains::class)->verify($actor, $this->team->id, $page->id))->toThrow(ModelNotFoundException::class);
    }
    expect(DB::table('status_domain_bindings')->count())->toBe(0);
})->with(['claim', 'membership']);

it('requires an authorized current tenant before changing claims or sending any DNS query', function (): void {
    $other = Team::factory()->create();
    expect(fn () => app(ManageStatusDomains::class)->save($other->owner, $other->id, $this->page->id, 'status.example.com'))->toThrow(ModelNotFoundException::class);
    $viewer = User::factory()->create();
    $this->team->users()->attach($viewer->id, ['role' => 'viewer']);
    expect(fn () => app(ManageStatusDomains::class)->verify($viewer, $this->team->id, $this->page->id))->toThrow(AuthorizationException::class)
        ->and($this->dns->queries)->toBeEmpty();
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('requires tenant ownership for management proof projections independently of the caller', function (): void {
    $page = verifiedStatusDomain();
    $registry = app(StatusDomainRegistry::class);
    expect(fn () => $registry->present($page))->toThrow(MissingTenantContext::class);
    $other = Team::factory()->create();
    app(TenantContext::class)->run($other->id, function () use ($registry, $page): void {
        expect(fn () => $registry->present($page))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });
    app(TenantContext::class)->run($this->team->id, function () use ($registry, $page): void {
        expect($registry->present($page)['proofValue'])->toBe('signalbox-verification='.$page->domain_challenge);
    });
});

it('keeps TLS admission internal stateless and limited to currently verified public domains', function (): void {
    verifiedStatusDomain();
    $count = count($this->dns->queries);
    $response = $this->get('/internal/status-domains/allow?domain=status.example.com')->assertNoContent();
    expect($response->headers->getCookies())->toBeEmpty()->and(count($this->dns->queries))->toBe($count);
    $this->get('/internal/status-domains/allow?domain=unknown.example.com')->assertNotFound();
    $this->get('/internal/status-domains/allow?domain[]=status.example.com')->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->get('/internal/status-domains/allow?domain=status.example.com', ['X-Forwarded-For' => '127.0.0.1'])->assertForbidden();
    $this->get('/internal/status-domains/%61llow?domain=status.example.com')->assertForbidden();
});

it('does not let forwarded host headers bypass the selected origin or expose another page', function (): void {
    verifiedStatusDomain();
    $this->get('https://status.example.com/account', ['X-Forwarded-Host' => 'localhost'])->assertNotFound();
    $this->get('https://unknown.example.com/status/domain-page', ['X-Forwarded-Host' => 'status.example.com'])->assertNotFound();
    $this->get('http://localhost/status/domain-page', ['X-Forwarded-Host' => 'unknown.example.com'])->assertOk();
});

it('revalidates only due claims within the CLI batch limit and restores tenant context', function (): void {
    $page = verifiedStatusDomain();
    $count = count($this->dns->queries);
    $this->artisan('status-pages:verify-domains', ['--limit' => '1'])->assertSuccessful();
    expect(count($this->dns->queries))->toBe($count);
    $this->travel(1)->hours();
    statusDomainDns($page, []);
    $this->artisan('status-pages:verify-domains', ['--limit' => '1'])->assertSuccessful();
    expect(count($this->dns->queries))->toBe($count + 1)->and(DB::table('status_domain_bindings')->count())->toBe(0);
    $this->artisan('status-pages:verify-domains', ['--limit' => '101'])->assertExitCode(2);
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('enforces the full pending claim generation and tenant at the database boundary', function (): void {
    $page = verifiedStatusDomain();
    $other = Team::factory()->create();
    foreach ([['team_id' => $other->id], ['challenge' => (string) Str::uuid()], ['hostname' => 'other.example.com']] as $changes) {
        expect(fn () => DB::transaction(fn () => DB::table('status_domain_bindings')->where('status_page_id', $page->id)->update($changes)))->toThrow(QueryException::class);
    }
});

it('enforces HTTPS outside local testing and trusts scheme headers only from explicit proxy peers', function (): void {
    verifiedStatusDomain();
    app()->instance('env', 'production');

    try {
        $this->get('http://status.example.com/status/domain-page', ['X-Forwarded-Proto' => 'https'])->assertNotFound();
        \Illuminate\Http\Middleware\TrustProxies::at(['127.0.0.1']);
        $this->get('http://status.example.com/status/domain-page', ['X-Forwarded-Proto' => 'https'])->assertOk();
        $this->get('https://status.example.com/account', ['X-Forwarded-Host' => 'localhost'])->assertNotFound();
    } finally {
        \Illuminate\Http\Middleware\TrustProxies::at([]);
        app()->instance('env', 'testing');
    }
});

it('requires a custom-origin CSRF token and preserves primary identity after public validation errors', function (): void {
    config(['session.driver' => 'redis']);
    verifiedStatusDomain();
    DB::table('status_pages')->where('id', $this->page->id)->update(['password_hash' => bcrypt('correct-password')]);
    $this->actingAs($this->team->owner)->withSession(['_token' => 'primary-token', 'private_marker' => 'primary']);
    app()->instance('env', 'production');

    try {
        $initial = $this->get('https://status.example.com/status/domain-page')->assertOk();
        $cookie = collect($initial->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'signalbox_status_'));
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $this->post('https://status.example.com/status/domain-page/unlock', ['password' => 'correct-password', '_token' => 'primary-token'])->assertStatus(419);
        $token = $initial->getCookie('XSRF-TOKEN')->getValue();
        $this->post('https://status.example.com/status/domain-page/unlock', ['password' => 'wrong-password', '_token' => $token])->assertRedirect();
        $this->get('https://status.example.com/status/domain-page')->assertInertia(fn (AssertableInertia $view) => $view
            ->component('StatusPages/Unlock')->where('errors.password', 'This password is not correct.')->where('auth.user', null));
    } finally {
        app()->instance('env', 'testing');
    }

    expect(session('private_marker'))->toBe('primary');
    $this->get('http://localhost/status/domain-page')->assertInertia(fn (AssertableInertia $view) => $view
        ->component('StatusPages/Unlock')->where('auth.user.email', $this->team->owner->email));
});

it('admits exactly one owner when different tenants prove the same hostname concurrently outside transactions', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $other = null;
    $children = [];

    try {
        $other = $fixture->committed(fn (): Team => Team::factory()->create());
        $hostname = 'race-'.Str::lower(Str::random(12)).'.example.com';
        $pages = $fixture->committed(function () use ($fixture, $other, $hostname): array {
            $pages = [];
            foreach ([Team::query()->findOrFail($fixture->teamId), $other] as $team) {
                $page = app(TenantContext::class)->run($team->id, fn (): StatusPage => StatusPage::factory()->create(['is_public' => true]));
                statusDomainClaim($page, $team, $hostname);
                $pages[] = ['team' => $team->id, 'page' => $page->id];
            }

            return $pages;
        });
        $fixture->connection->beginTransaction();
        $fixture->connection->table('teams')->whereIn('id', [$fixture->teamId, $other->id])->orderBy('id')->lockForUpdate()->get();
        foreach ($pages as $page) {
            $child = new Process([PHP_BINARY, base_path('tests/Fixtures/RunStatusDomainProbe.php'), (string) $page['team'], (string) $page['page']], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_DEBUG' => 'false',
                'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) config('database.connections.pgsql.host'),
                'DB_PORT' => (string) config('database.connections.pgsql.port'),
                'DB_DATABASE' => TestDatabaseGuard::DATABASE, 'DB_USERNAME' => TestDatabaseGuard::USERNAME,
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'), 'DB_URL' => '',
                'REDIS_HOST' => (string) config('database.redis.default.host'), 'REDIS_URL' => '',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
                'STATUS_DOMAIN_PROBE_NOW' => CarbonImmutable::now()->toIso8601String(),
            ], timeout: 15);
            $child->start();
            $children[] = $child;
        }

        $deadline = microtime(true) + 5;
        do {
            $fixture->connection->select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) $fixture->connection->scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'signalbox-status-domain-probe' AND wait_event_type = 'Lock'");
            if ($waiting === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($waiting)->toBe(2);
        $fixture->connection->commit();
        $accepted = 0;
        foreach ($children as $child) {
            $child->wait();
            expect($child->isSuccessful())->toBeTrue($child->getErrorOutput());
            $accepted += json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR)['accepted'] ? 1 : 0;
        }
        expect($accepted)->toBe(1)->and($fixture->connection->table('status_domain_bindings')->where('hostname', $hostname)->count())->toBe(1);
    } finally {
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }
        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->stop();
            }
        }
        if ($other !== null) {
            $fixture->connection->table('teams')->where('id', $other->id)->delete();
            $fixture->connection->table('users')->where('id', $other->owner_id)->delete();
        }
        $fixture->cleanup();
    }
});
