<?php

declare(strict_types=1);

/**
 * Acceptance matrix of the public status page boundary on PostgreSQL and Redis. Two tenants share the same surfaces at
 * once, so these cases combine what the single-feature suites prove one at a time: monitor selection, private data on
 * every origin, password and warm cache transitions, mailbox audiences and hostnames without a live proof.
 *
 * Every private fixture value contains the word "sentinel", so one case-insensitive scan covers all of them, and each
 * scan has a positive control proving that it read the published content.
 */

use App\Actions\Incidents\ManageIncidents;
use App\Actions\Incidents\ManageMaintenanceWindows;
use App\Actions\StatusPages\ManageStatusDomains;
use App\Actions\StatusPages\ManageStatusPages;
use App\Contracts\Monitoring\DnsTransport;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Exceptions\MissingTenantContext;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageDelivery;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusSubscriptionLinks;
use App\Services\StatusPages\StatusSubscriptions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use Tests\Support\FakeDnsTransport;

pest()->group('docker');

/** Hostname proved by the first tenant in every case's starting state. */
const ISOLATION_HOST = 'status.alpha.example.com';

/** Password shared by both tenants when a case must prove that equal passwords still do not cross pages. */
const ISOLATION_PASSWORD = 'sentinel-shared-password';

/** Text published by the first tenant, used as the positive control of every scan. */
const ISOLATION_PUBLISHED = 'Alpha published text';

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL and Redis.');
    }

    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    Cache::flush();

    // A custom origin builds a new session manager per request, so its unlocks persist only through Redis and the cookie.
    config(['session.driver' => 'redis']);

    $this->dns = new FakeDnsTransport();
    $this->app->instance(DnsTransport::class, $this->dns);

    $this->alpha = isolationTenant('Sentinel team name', 'sentinel-owner@example.test', 'alpha-status', 'Alpha status', 'Alpha API');
    $this->beta = isolationTenant('Second team', 'second-owner@example.test', 'beta-status', 'Beta status', 'Beta API');

    isolationPrivateRecords($this->alpha);
    isolationClaim($this->alpha);
    isolationProve($this->alpha);

    expect(isolationVerify($this->alpha))->toBeTrue();
});

/**
 * @param string $teamName Private team name.
 * @param string $ownerEmail Private owner mailbox.
 * @param string $slug Public address of the tenant's page.
 * @param string $title Public page title.
 * @param string $component Public component name.
 * @return object{team: Team, monitor: Monitor, page: StatusPage, component: string} Tenant fixture with a published page.
 */
function isolationTenant(string $teamName, string $ownerEmail, string $slug, string $title, string $component): object
{
    $team = Team::factory()->create(['name' => $teamName]);
    $team->owner->forceFill(['name' => 'Sentinel owner name', 'email' => $ownerEmail])->save();

    $monitor = app(TenantContext::class)->run($team->id, static function () use ($team): Monitor {
        $monitor = Monitor::factory()->create(['name' => 'Sentinel monitor name', 'team_id' => $team->id]);

        $monitor->applyConfiguration(new HttpMonitorConfiguration(
            'https://sentinel-monitor-url.example.invalid/health',
            HttpMethod::POST,
            ['X-Private' => 'sentinel-monitor-header'],
            'sentinel-monitor-body',
        ));
        $monitor->save();

        return $monitor;
    });

    $page = app(ManageStatusPages::class)->create($team->owner, $team->id, isolationInput($slug, $title, $component, $monitor));

    return (object) ['team' => $team, 'monitor' => $monitor, 'page' => $page, 'component' => $component];
}

/**
 * @param string $slug Public address.
 * @param string $title Public title.
 * @param string $component Public component name.
 * @param Monitor $monitor Monitor of the only component.
 * @param array<string, mixed> $overrides Replaced input fields.
 * @return StatusPageInput Published page input.
 */
function isolationInput(string $slug, string $title, string $component, Monitor $monitor, array $overrides = []): StatusPageInput
{
    return new StatusPageInput(...[
        'slug' => $slug,
        'title' => $title,
        'description' => null,
        'theme' => new StatusPageTheme(StatusPageThemeMode::System, StatusPageAccent::Emerald),
        'isPublic' => true,
        'password' => null,
        'removePassword' => false,
        'components' => [['name' => $component, 'monitor_ids' => [$monitor->id]]],
        ...$overrides,
    ]);
}

/**
 * Keeps the page's slug, title and component unless replaced, so a case states only what it changes.
 *
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Page owner.
 * @param array<string, mixed> $overrides Replaced input fields.
 */
function isolationChangePage(object $tenant, array $overrides): void
{
    $current = app(TenantContext::class)->run($tenant->team->id, static fn (): StatusPage => StatusPage::query()->findOrFail($tenant->page->id));

    app(ManageStatusPages::class)->update(
        $tenant->team->owner,
        $tenant->team->id,
        $tenant->page->id,
        isolationInput($current->slug, $current->title, $tenant->component, $tenant->monitor, $overrides),
    );
}

/**
 * Fill every private place a public response could read by mistake, next to one published entry.
 *
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Tenant receiving the records.
 */
function isolationPrivateRecords(object $tenant): void
{
    $team = $tenant->team;

    $incident = app(TenantContext::class)->run($team->id, static fn (): Incident => Incident::factory()->create([
        'monitor_id' => $tenant->monitor->id,
        'root_cause_note' => 'Sentinel declared cause',
    ]));

    $incidents = app(ManageIncidents::class);
    $incidents->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Identified, 'Sentinel private entry');

    $published = $incidents->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Investigating, ISOLATION_PUBLISHED);
    $incidents->publish($team->owner, $team->id, $published->id);

    app(ManageMaintenanceWindows::class)->schedule(
        $team->owner,
        $team->id,
        $tenant->monitor->id,
        null,
        CarbonImmutable::now('UTC')->addHour(),
        'Sentinel maintenance reason',
    );
}

/**
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Claim owner.
 * @param string $hostname Claimed name.
 * @return StatusPage Page with its current proof generation.
 */
function isolationClaim(object $tenant, string $hostname = ISOLATION_HOST): StatusPage
{
    app(ManageStatusDomains::class)->save($tenant->team->owner, $tenant->team->id, $tenant->page->id, $hostname);

    return isolationFreshPage($tenant);
}

/**
 * Publish TXT answers for the claimed hostname, as the customer's DNS would.
 *
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} ...$tenants Owners whose current proofs are all published.
 */
function isolationProve(object ...$tenants): void
{
    $name = '_signalbox.'.ISOLATION_HOST.'.';
    $records = [];

    foreach ($tenants as $tenant) {
        $value = 'signalbox-verification='.isolationFreshPage($tenant)->domain_challenge;
        $records[] = new Record($name, Message::TYPE_TXT, Message::CLASS_IN, 60, [$value]);
    }

    test()->dns->answers[$name.'|'.Message::TYPE_TXT] = Message::createResponseWithAnswersForQuery(
        new Query($name, Message::TYPE_TXT, Message::CLASS_IN),
        $records,
    );
}

/**
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Claim owner.
 * @return bool Whether this claim owns a live association after the check.
 */
function isolationVerify(object $tenant): bool
{
    return app(ManageStatusDomains::class)->verify($tenant->team->owner, $tenant->team->id, $tenant->page->id);
}

/**
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Page owner.
 * @return StatusPage Current row, read inside the owning tenant.
 */
function isolationFreshPage(object $tenant): StatusPage
{
    return app(TenantContext::class)->run($tenant->team->id, static fn (): StatusPage => StatusPage::query()->findOrFail($tenant->page->id));
}

/**
 * Keep the custom-origin cookie across calls, as a browser does. A cookie with another name stays in the jar and is ignored.
 *
 * @param string $method GET, HEAD or POST.
 * @param string $path Path on the custom origin.
 * @param array<string, mixed> $data Form fields.
 * @param array<string, string> $headers Request headers.
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response> Response of the custom origin.
 */
function isolationAlias(string $method, string $path, array $data = [], array $headers = []): TestResponse
{
    $test = test();
    $url = 'https://'.ISOLATION_HOST.$path;

    // Only these helpers send the cookie jar; HEAD is never used to continue a session here.
    $response = match ($method) {
        'GET' => $test->get($url, $headers),
        'POST' => $test->post($url, $data, $headers),
        'HEAD' => $test->call('HEAD', $url),
    };

    foreach ($response->headers->getCookies() as $cookie) {
        if (str_starts_with($cookie->getName(), 'signalbox_status_')) {
            $test->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
        }
    }

    return $response;
}

/**
 * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response Custom-origin response.
 * @return string Name of its isolated session cookie.
 */
function isolationSessionCookieName(TestResponse $response): string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if (str_starts_with($cookie->getName(), 'signalbox_status_')) {
            return $cookie->getName();
        }
    }

    throw new LogicException('The custom origin answered without its session cookie.');
}

/**
 * Forget the primary session, whose store survives between calls in one test, so the next call is a new visitor.
 */
function isolationNewPrimaryVisitor(): void
{
    app('session.store')->flush();
}

/**
 * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response Public response.
 * @return string Lowercase headers and body, for a scan that also covers cookies, links and robots directives.
 */
function isolationExposure(TestResponse $response): string
{
    return mb_strtolower((string) $response->headers.$response->getContent());
}

/**
 * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response Public response.
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Tenant whose exact secrets are looked for.
 */
function isolationAssertNothingPrivate(TestResponse $response, object $tenant): void
{
    $exposure = isolationExposure($response);
    $page = isolationFreshPage($tenant);

    expect($exposure)->not->toContain('sentinel')
        ->not->toContain('team_id')
        ->not->toContain('monitor_id')
        ->not->toContain('password_hash')
        ->not->toContain('domain_challenge');

    if ($page->domain_challenge !== null) {
        expect($exposure)->not->toContain($page->domain_challenge);
    }

    if ($page->password_hash !== null) {
        expect($exposure)->not->toContain(mb_strtolower($page->password_hash));
    }
}

/**
 * @param string $surface Name from the public surface list.
 * @param bool $alias Whether the request reaches the custom origin instead of the primary one.
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response> Response of that surface.
 */
function isolationSurface(string $surface, bool $alias): TestResponse
{
    $path = '/status/alpha-status';

    $headers = match ($surface) {
        'document' => [],
        'visit' => ['X-Inertia' => 'true'],
        'partial' => [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'StatusPages/Public',
            'X-Inertia-Partial-Data' => 'page',
        ],
    };

    if ($alias) {
        return isolationAlias('GET', $path, [], $headers);
    }

    return test()->get('http://localhost'.$path, $headers);
}

/**
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Page owner.
 * @param string $email Mailbox.
 * @param bool $verified Whether to confirm consent.
 * @return StatusPageSubscriber Recipient of the tenant's page.
 */
function isolationSubscriber(object $tenant, string $email, bool $verified = true): StatusPageSubscriber
{
    $page = isolationFreshPage($tenant);

    // Consent on a protected page needs an unlocked session, as it does for any visitor.
    if ($page->password_hash !== null) {
        test()->post('http://localhost/status/'.$page->slug.'/unlock', ['password' => ISOLATION_PASSWORD]);
    }

    app(StatusSubscriptions::class)->subscribe($page, app('session.store'), $email);

    $hash = hash_hmac('sha256', $email, (string) config('app.key'));

    $subscriber = app(TenantContext::class)->run($tenant->team->id, static fn (): StatusPageSubscriber => StatusPageSubscriber::query()
        ->where('status_page_id', $page->id)
        ->where('email_hash', $hash)
        ->firstOrFail());

    if ($verified) {
        app(StatusSubscriptions::class)->act($tenant->team->id, $page->id, $subscriber->id, $subscriber->generation, 'verify', true);
    }

    return $subscriber;
}

/**
 * @param object{team: Team, monitor: Monitor, page: StatusPage, component: string} $tenant Publishing tenant.
 * @param string $message Published text.
 */
function isolationPublish(object $tenant, string $message): void
{
    $incident = app(TenantContext::class)->run($tenant->team->id, static fn (): Incident => Incident::query()->where('monitor_id', $tenant->monitor->id)->first()
        ?? Incident::factory()->create(['monitor_id' => $tenant->monitor->id]));

    $entry = app(ManageIncidents::class)->postUpdate($tenant->team->owner, $tenant->team->id, $incident->id, IncidentStatus::Investigating, $message);

    app(ManageIncidents::class)->publish($tenant->team->owner, $tenant->team->id, $entry->id);
}

/**
 * @param int $teamId Owning tenant.
 * @param string $kind Delivery kind.
 * @return list<StatusPageDelivery> Deliveries of that tenant, oldest first.
 */
function isolationDeliveries(int $teamId, string $kind): array
{
    return app(TenantContext::class)->run($teamId, static fn (): array => array_values(
        StatusPageDelivery::query()->where('kind', $kind)->orderBy('id')->get()->all(),
    ));
}

/**
 * @param array<string, mixed> $fields Replaced request fields.
 * @return array<string, mixed> Valid management form body.
 */
function isolationForm(array $fields): array
{
    return [
        'slug' => 'formed-status',
        'title' => 'Formed status',
        'description' => '',
        'theme_mode' => 'system',
        'theme_accent' => 'emerald',
        'is_public' => true,
        ...$fields,
    ];
}

/**
 * @param int $teamId Owning tenant.
 * @param int $pageId Page.
 * @return list<array{string, list<int>}> Component names with their selected monitors, in page order.
 */
function isolationSelection(int $teamId, int $pageId): array
{
    return DB::table('status_page_components as component')
        ->leftJoin('status_page_component_monitors as selection', 'selection.status_page_component_id', '=', 'component.id')
        ->where('component.team_id', $teamId)
        ->where('component.status_page_id', $pageId)
        ->orderBy('component.position')
        ->orderBy('selection.monitor_id')
        ->get(['component.name', 'selection.monitor_id'])
        ->groupBy('name')
        ->map(static fn ($rows, string $name): array => [$name, $rows->pluck('monitor_id')->map(intval(...))->all()])
        ->values()
        ->all();
}

/**
 * Inertia leaves validation errors in the session as a plain array of messages per bag or field.
 *
 * @return array<mixed> Errors flashed by the latest request; empty when it flashed none.
 */
function isolationSessionErrors(): array
{
    $errors = session('errors');

    return is_array($errors) ? $errors : [];
}

/**
 * @param Team $team Team the user joins.
 * @param User $user Member of another team too.
 */
function isolationJoinAsAdmin(Team $team, User $user): void
{
    $team->users()->attach($user->id, ['role' => 'admin']);
}

it('refuses a monitor of another team through the web routes even for a manager of both teams', function (string $operation): void {
    $alpha = $this->alpha;
    $beta = $this->beta;
    isolationJoinAsAdmin($beta->team, $alpha->team->owner);

    $route = "http://localhost/teams/{$beta->team->id}/status-pages";

    if ($operation === 'update') {
        $route .= "/{$beta->page->id}";
    } else {
        // Creating needs a free slot, so the plan limit cannot answer in place of the selection rule.
        app(TenantContext::class)->run($beta->team->id, static fn () => $beta->page->delete());
    }

    $selected = isolationSelection($beta->team->id, $beta->page->id);

    $submit = function (array $monitorIds) use ($operation, $route, $alpha): array {
        $form = isolationForm(['components' => [['name' => 'Mixed', 'monitor_ids' => $monitorIds]]]);
        $this->actingAs($alpha->team->owner)->from($route);

        $response = $operation === 'update' ? $this->patch($route, $form) : $this->post($route, $form);
        $response->assertRedirect();

        return isolationSessionErrors();
    };

    $foreign = $submit([$beta->monitor->id, $alpha->monitor->id]);
    $missing = $submit([$beta->monitor->id, 999_999_999]);

    expect($foreign)->not->toBeEmpty()
        ->and($foreign)->toBe($missing);

    // The refused save changes nothing, including the local monitor submitted next to the foreign one.
    expect(isolationSelection($beta->team->id, $beta->page->id))->toBe($selected)
        ->and(DB::table('status_page_component_monitors')->where('team_id', $beta->team->id)->where('monitor_id', $alpha->monitor->id)->count())->toBe(0)
        ->and(DB::table('status_pages')->where('slug', 'formed-status')->count())->toBe(0);

    // The same form with the local monitor alone is accepted, so the refusals above came from the selection rule.
    expect($submit([$beta->monitor->id]))->toBe([]);

    $saved = DB::table('status_pages')->where('team_id', $beta->team->id)->where('slug', 'formed-status')->value('id');

    expect(isolationSelection($beta->team->id, (int) $saved))->toBe([['Mixed', [$beta->monitor->id]]]);
})->with(['create', 'update']);

it('answers 404 for a page of another team under the route team, for a manager of both teams', function (string $method, string $suffix): void {
    $alpha = $this->alpha;
    $beta = $this->beta;
    isolationJoinAsAdmin($beta->team, $alpha->team->owner);

    $before = (array) DB::table('status_pages')->where('id', $alpha->page->id)->first();
    $binding = DB::table('status_domain_bindings')->where('status_page_id', $alpha->page->id)->count();

    $body = match ($suffix) {
        '' => isolationForm(['components' => []]),
        '/domain' => ['domain' => 'taken-over.example.com'],
        '/domain/verify' => [],
    };

    $this->actingAs($alpha->team->owner)
        ->call($method, "http://localhost/teams/{$beta->team->id}/status-pages/{$alpha->page->id}{$suffix}", $body)
        ->assertNotFound();

    expect((array) DB::table('status_pages')->where('id', $alpha->page->id)->first())->toBe($before)
        ->and(DB::table('status_domain_bindings')->where('status_page_id', $alpha->page->id)->count())->toBe($binding);
})->with([
    'edit' => ['PATCH', ''],
    'delete' => ['DELETE', ''],
    'claim' => ['PUT', '/domain'],
    'verify' => ['POST', '/domain/verify'],
    'remove claim' => ['DELETE', '/domain'],
]);

it('exposes nothing private on any origin or protocol of a public page', function (string $surface, bool $alias): void {
    $response = isolationSurface($surface, $alias)->assertOk();

    expect($response->getContent())->toContain(ISOLATION_PUBLISHED)
        ->toContain('Alpha API');

    isolationAssertNothingPrivate($response, $this->alpha);

    // The custom origin is known to whoever reached it; the primary address must not reveal which alias a page has.
    if (!$alias) {
        expect(isolationExposure($response))->not->toContain(ISOLATION_HOST);
    }

    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
})->with(['document', 'visit', 'partial'])->with(['primary' => false, 'custom origin' => true]);

it('keeps HEAD responses and the stored projection free of private values', function (): void {
    $alpha = $this->alpha;
    $this->actingAs($alpha->team->owner);

    // A member warms the cache: nothing about them may reach the store or the next anonymous visitor.
    $this->get('http://localhost/status/alpha-status')->assertOk();
    auth()->logout();
    isolationNewPrimaryVisitor();

    isolationAssertNothingPrivate($this->call('HEAD', 'http://localhost/status/alpha-status')->assertOk(), $alpha);
    isolationAssertNothingPrivate(isolationAlias('HEAD', '/status/alpha-status')->assertOk(), $alpha);

    $anonymous = $this->get('http://localhost/status/alpha-status')->assertOk();
    isolationAssertNothingPrivate($anonymous, $alpha);
    $anonymous->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));

    $generation = Cache::get("status-pages:{$alpha->team->id}:{$alpha->page->id}:generation");
    $stored = Cache::get("status-pages:{$alpha->team->id}:{$alpha->page->id}:content:{$generation}");

    expect($stored)->toBeArray();

    $encoded = mb_strtolower((string) json_encode($stored, JSON_THROW_ON_ERROR));

    expect($encoded)->toContain(mb_strtolower(ISOLATION_PUBLISHED))
        ->not->toContain('sentinel')
        ->not->toContain($alpha->team->owner->email);
});

it('hides a protected page on every origin and protocol until its own session is unlocked, with a warm cache', function (string $surface, bool $alias): void {
    $alpha = $this->alpha;

    // Warm the content first: only the live password check may stand between the store and the visitor.
    isolationSurface('document', $alias)->assertOk();
    isolationChangePage($alpha, ['password' => ISOLATION_PASSWORD]);

    $locked = isolationSurface($surface, $alias)->assertOk();

    expect($locked->getContent())->not->toContain(ISOLATION_PUBLISHED)
        ->not->toContain('Alpha status')
        ->not->toContain('Alpha API');

    isolationAssertNothingPrivate($locked, $alpha);

    if ($alias) {
        isolationAlias('POST', '/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();
    } else {
        $this->post('http://localhost/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();
    }

    $unlocked = isolationSurface($surface, $alias)->assertOk();

    expect($unlocked->getContent())->toContain(ISOLATION_PUBLISHED);
    isolationAssertNothingPrivate($unlocked, $alpha);
})->with(['document', 'visit', 'partial'])->with(['primary' => false, 'custom origin' => true]);

it('does not carry an unlock between origins, in either direction', function (): void {
    $alpha = $this->alpha;
    isolationChangePage($alpha, ['password' => ISOLATION_PASSWORD]);

    $this->post('http://localhost/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();
    $this->get('http://localhost/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Public'));

    isolationAlias('GET', '/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock'));

    isolationNewPrimaryVisitor();

    isolationAlias('POST', '/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();
    isolationAlias('GET', '/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Public'));

    $this->get('http://localhost/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock'));
});

it('applies password and draft changes at once on both origins for sessions already unlocked', function (): void {
    $alpha = $this->alpha;
    isolationChangePage($alpha, ['password' => ISOLATION_PASSWORD]);

    $this->post('http://localhost/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();
    isolationAlias('POST', '/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();

    $component = static function (string $name): Closure {
        return static fn (AssertableInertia $page) => $page->component($name);
    };

    $this->get('http://localhost/status/alpha-status')->assertInertia($component('StatusPages/Public'));
    isolationAlias('GET', '/status/alpha-status')->assertInertia($component('StatusPages/Public'));

    // A new password invalidates the fingerprint in both sessions.
    isolationChangePage($alpha, ['password' => 'sentinel-other-password']);

    $this->get('http://localhost/status/alpha-status')->assertInertia($component('StatusPages/Unlock'));
    isolationAlias('GET', '/status/alpha-status')->assertInertia($component('StatusPages/Unlock'));

    // Setting the first password again produces another hash, so the old unlock does not come back.
    isolationChangePage($alpha, ['password' => ISOLATION_PASSWORD]);

    $this->get('http://localhost/status/alpha-status')->assertInertia($component('StatusPages/Unlock'));
    isolationAlias('GET', '/status/alpha-status')->assertInertia($component('StatusPages/Unlock'));

    isolationChangePage($alpha, ['removePassword' => true]);

    $this->get('http://localhost/status/alpha-status')->assertInertia($component('StatusPages/Public'));
    isolationAlias('GET', '/status/alpha-status')->assertInertia($component('StatusPages/Public'));

    isolationChangePage($alpha, ['isPublic' => false]);

    $this->get('http://localhost/status/alpha-status')->assertNotFound();
    isolationAlias('GET', '/status/alpha-status')->assertNotFound();
});

it('counts wrong passwords together across origins', function (): void {
    isolationChangePage($this->alpha, ['password' => ISOLATION_PASSWORD]);

    foreach (range(1, 5) as $attempt) {
        $this->post('http://localhost/status/alpha-status/unlock', ['password' => 'wrong-'.$attempt])->assertRedirect();
    }

    isolationAlias('POST', '/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertTooManyRequests();
    isolationAlias('GET', '/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock'));
});

it('gives a reused address nothing of its previous tenant', function (): void {
    $alpha = $this->alpha;
    $beta = $this->beta;
    isolationChangePage($alpha, ['password' => ISOLATION_PASSWORD]);

    $this->post('http://localhost/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();
    $this->get('http://localhost/status/alpha-status')->assertOk();

    $subscriber = isolationSubscriber($alpha, 'reader@example.test');
    $link = app(StatusSubscriptionLinks::class)->action($subscriber, 'unsubscribe');
    $link = (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);

    isolationChangePage($alpha, ['slug' => 'alpha-moved']);
    isolationChangePage($beta, ['slug' => 'alpha-status', 'password' => ISOLATION_PASSWORD]);

    // The same session and the same password: the unlock belongs to the page identity, not to its address.
    $this->get('http://localhost/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock'));

    $this->post('http://localhost/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();

    $reused = $this->get('http://localhost/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('StatusPages/Public')
        ->where('page.title', 'Beta status'));

    expect($reused->getContent())->not->toContain('Alpha status')
        ->not->toContain(ISOLATION_PUBLISHED);

    // The custom origin follows its own page to the new address and refuses the other tenant's page at the old one.
    isolationAlias('GET', '/')->assertRedirect('https://'.ISOLATION_HOST.'/status/alpha-moved');
    isolationAlias('GET', '/status/alpha-status')->assertNotFound();

    // A capability names identities, so it keeps acting on the first tenant's recipient only.
    $this->post('http://localhost'.$link)->assertOk();

    expect(DB::table('status_page_subscribers')->where('id', $subscriber->id)->whereNotNull('unsubscribed_at')->count())->toBe(1)
        ->and(DB::table('status_page_subscribers')->where('status_page_id', $beta->page->id)->count())->toBe(0);
});

it('refuses a page that took the admitted address between the host check and the controller', function (string $method, string $suffix): void {
    $alpha = $this->alpha;
    $beta = $this->beta;

    // RouteMatched fires after the global host guard admitted the first tenant's page and before its controller runs.
    Event::listen(RouteMatched::class, static function () use ($alpha, $beta): void {
        DB::table('status_pages')->where('id', $alpha->page->id)->update(['slug' => 'alpha-moved']);
        DB::table('status_pages')->where('id', $beta->page->id)->update(['slug' => 'alpha-status']);
    });

    isolationAlias($method, '/status/alpha-status'.$suffix, ['email' => 'reader@example.test', 'password' => 'anything'])->assertNotFound();

    expect(DB::table('status_page_subscribers')->count())->toBe(0);
})->with([
    'read' => ['GET', ''],
    'unlock' => ['POST', '/unlock'],
    'subscribe' => ['POST', '/subscribe'],
]);

it('sends a publication only to the current consenting audience of the pages showing its monitor', function (): void {
    $alpha = $this->alpha;
    $beta = $this->beta;

    $active = isolationSubscriber($alpha, 'active@example.test');
    isolationSubscriber($alpha, 'pending@example.test', verified: false);

    $left = isolationSubscriber($alpha, 'left@example.test');
    app(StatusSubscriptions::class)->act($alpha->team->id, $alpha->page->id, $left->id, $left->generation, 'unsubscribe', true);

    // The same mailbox on a page of another team, and a second tenant publishing nothing about this monitor.
    isolationSubscriber($beta, 'active@example.test');
    isolationSubscriber($beta, 'beta-only@example.test');

    // The audience is under test, not the plan limit on the number of pages.
    config(['plans.free.status_pages' => 10, 'plans.pro.status_pages' => 10]);

    // Pages of the same team that must stay silent: without the monitor, returned to draft, and with a new password.
    $team = $alpha->team;
    $pages = app(ManageStatusPages::class);
    $other = app(TenantContext::class)->run($team->id, static fn (): Monitor => Monitor::factory()->create());

    $elsewhere = (object) [...(array) $alpha, 'monitor' => $other, 'component' => 'Elsewhere'];
    $elsewhere->page = $pages->create($team->owner, $team->id, isolationInput('alpha-elsewhere', 'Elsewhere', 'Elsewhere', $other));
    isolationSubscriber($elsewhere, 'active@example.test');

    $draft = (object) [...(array) $alpha, 'component' => 'Draft'];
    $draft->page = $pages->create($team->owner, $team->id, isolationInput('alpha-draft', 'Draft', 'Draft', $alpha->monitor));
    isolationSubscriber($draft, 'active@example.test');
    isolationChangePage($draft, ['isPublic' => false]);

    $protected = (object) [...(array) $alpha, 'component' => 'Protected'];
    $protected->page = $pages->create($team->owner, $team->id, isolationInput('alpha-protected', 'Protected', 'Protected', $alpha->monitor));
    isolationSubscriber($protected, 'active@example.test');
    isolationChangePage($protected, ['password' => ISOLATION_PASSWORD]);

    isolationPublish($alpha, 'Alpha second text');

    $updates = isolationDeliveries($team->id, 'update');

    expect($updates)->toHaveCount(1)
        ->and($updates[0]->subscriber_id)->toBe($active->id)
        ->and($updates[0]->status_page_id)->toBe($alpha->page->id)
        ->and(isolationDeliveries($beta->team->id, 'update'))->toBeEmpty();

    $text = mb_strtolower($updates[0]->payload['text']);

    expect($text)->toContain('alpha second text')
        ->toContain('alpha status')
        ->toContain('alpha api')
        ->not->toContain('sentinel')
        ->not->toContain('beta')
        ->not->toContain('active@example.test')
        ->not->toContain(ISOLATION_HOST);

    // Publishing in the other tenant reaches only its recipients, even with the shared mailbox.
    isolationPublish($beta, 'Beta second text');

    expect(isolationDeliveries($team->id, 'update'))->toHaveCount(1)
        ->and(isolationDeliveries($beta->team->id, 'update'))->toHaveCount(2);

    // Consent is per page: leaving the other tenant's page leaves this one untouched.
    $sharedBeta = app(TenantContext::class)->run($beta->team->id, static fn (): StatusPageSubscriber => StatusPageSubscriber::query()
        ->where('email_hash', hash_hmac('sha256', 'active@example.test', (string) config('app.key')))
        ->firstOrFail());

    app(StatusSubscriptions::class)->act($beta->team->id, $beta->page->id, $sharedBeta->id, $sharedBeta->generation, 'unsubscribe', true);

    expect(DB::table('status_page_subscribers')->where('id', $active->id)->whereNull('unsubscribed_at')->count())->toBe(1);
});

it('answers a subscription request identically for every state of the mailbox', function (): void {
    $alpha = $this->alpha;

    isolationSubscriber($alpha, 'pending@example.test', verified: false);
    isolationSubscriber($alpha, 'active@example.test');

    $left = isolationSubscriber($alpha, 'left@example.test');
    app(StatusSubscriptions::class)->act($alpha->team->id, $alpha->page->id, $left->id, $left->generation, 'unsubscribe', true);

    $answers = [];

    foreach (['new@example.test', 'pending@example.test', 'active@example.test', 'left@example.test'] as $mailbox) {
        $response = $this->post('http://localhost/status/alpha-status/subscribe', ['email' => $mailbox])->assertRedirect();

        $page = $this->get('http://localhost/status/alpha-status')->assertOk();
        $props = $page->viewData('page')['props'];

        expect(mb_strtolower((string) json_encode($props, JSON_THROW_ON_ERROR)))->not->toContain($mailbox);

        $answers[] = [
            $response->getStatusCode(),
            $response->headers->get('Location'),
            $props['status'],
            array_keys($props),
        ];
    }

    expect(array_unique($answers, SORT_REGULAR))->toHaveCount(1);
});

it('routes custom-origin subscriptions to the bound page with mail links only on the primary origin', function (): void {
    $alpha = $this->alpha;
    $beta = $this->beta;
    config(['app.url' => 'https://app.signalbox.com']);

    isolationAlias('POST', '/status/beta-status/subscribe', ['email' => 'reader@example.test'])->assertNotFound();

    expect(DB::table('status_page_subscribers')->count())->toBe(0);

    isolationAlias('POST', '/status/alpha-status/subscribe', ['email' => 'reader@example.test'])
        ->assertRedirect('https://'.ISOLATION_HOST.'/status/alpha-status');

    expect(DB::table('status_page_subscribers')->where('team_id', $alpha->team->id)->count())->toBe(1)
        ->and(DB::table('status_page_subscribers')->where('team_id', $beta->team->id)->count())->toBe(0);

    $text = isolationDeliveries($alpha->team->id, 'verification')[0]->payload['text'];

    expect($text)->not->toContain(ISOLATION_HOST)
        ->not->toContain('Alpha status')
        ->not->toContain('reader@example.test');

    preg_match('#https://app\.signalbox\.com(/status/subscriptions/\S+/verify\S*)#', $text, $match);

    expect($match)->toHaveKey(1);

    // A capability is refused on the custom origin, and answered on the primary one.
    isolationAlias('POST', $match[1])->assertNotFound();

    expect(DB::table('status_page_subscribers')->whereNotNull('verified_at')->count())->toBe(0);

    $this->post('http://localhost'.$match[1])->assertOk();

    expect(DB::table('status_page_subscribers')->whereNotNull('verified_at')->count())->toBe(1);
});

it('requires the unlock of the same origin before subscribing to a protected page', function (): void {
    isolationChangePage($this->alpha, ['password' => ISOLATION_PASSWORD]);

    $this->post('http://localhost/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();

    isolationAlias('POST', '/status/alpha-status/subscribe', ['email' => 'reader@example.test'])->assertForbidden();

    expect(DB::table('status_page_subscribers')->count())->toBe(0);

    isolationNewPrimaryVisitor();
    isolationAlias('POST', '/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();

    $this->post('http://localhost/status/alpha-status/subscribe', ['email' => 'reader@example.test'])->assertForbidden();

    expect(DB::table('status_page_subscribers')->count())->toBe(0);

    isolationAlias('POST', '/status/alpha-status/subscribe', ['email' => 'reader@example.test'])->assertRedirect();

    expect(DB::table('status_page_subscribers')->count())->toBe(1);
});

it('refuses a correctly signed capability whose coordinates cross tenants or pages', function (string $team, string $page, string $recipient): void {
    $alpha = $this->alpha;

    $sibling = (object) [...(array) $alpha, 'component' => 'Sibling'];
    $sibling->page = app(ManageStatusPages::class)->create(
        $alpha->team->owner,
        $alpha->team->id,
        isolationInput('alpha-sibling', 'Sibling', 'Sibling', $alpha->monitor),
    );

    $fixtures = ['alpha' => $alpha, 'beta' => $this->beta, 'sibling' => $sibling];
    $recipients = [
        'alpha' => isolationSubscriber($alpha, 'reader@example.test'),
        'beta' => isolationSubscriber($this->beta, 'reader@example.test'),
        'sibling' => isolationSubscriber($sibling, 'reader@example.test'),
    ];

    $path = URL::signedRoute('status-subscriptions.action', [
        'teamId' => $fixtures[$team]->team->id,
        'pageId' => $fixtures[$page]->page->id,
        'subscriberId' => $recipients[$recipient]->id,
        'generation' => $recipients[$recipient]->generation,
        'action' => 'unsubscribe',
    ], absolute: false);

    $this->get('http://localhost'.$path)->assertNotFound();
    $this->post('http://localhost'.$path)->assertNotFound();

    expect(DB::table('status_page_subscribers')->whereNotNull('unsubscribed_at')->count())->toBe(0);
})->with([
    'tenant of another page' => ['beta', 'alpha', 'alpha'],
    'page of another tenant' => ['alpha', 'beta', 'beta'],
    'recipient of another tenant' => ['alpha', 'alpha', 'beta'],
    'recipient of the requested tenant only' => ['beta', 'beta', 'alpha'],
    'recipient of another page of the same tenant' => ['alpha', 'alpha', 'sibling'],
]);

it('serves every surface of a live verified domain, as the control of the refusals below', function (): void {
    isolationAlias('GET', '/')->assertRedirect('https://'.ISOLATION_HOST.'/status/alpha-status');
    isolationAlias('GET', '/status/alpha-status')->assertOk();
    isolationAlias('HEAD', '/status/alpha-status')->assertOk();
    isolationAlias('POST', '/status/alpha-status/unlock', ['password' => 'anything'])->assertRedirect();
    isolationAlias('POST', '/status/alpha-status/subscribe', ['email' => 'reader@example.test'])->assertRedirect();
    $this->get('http://localhost/internal/status-domains/allow?domain='.ISOLATION_HOST)->assertNoContent();

    expect(DB::table('status_page_subscribers')->count())->toBe(1);
});

it('refuses every surface of a hostname without a live proof, for routing and certificates alike', function (string $state): void {
    $alpha = $this->alpha;
    $domains = app(ManageStatusDomains::class);

    match ($state) {
        'never proved' => (static function () use ($domains, $alpha): void {
            $domains->save($alpha->team->owner, $alpha->team->id, $alpha->page->id, null);
            isolationClaim($alpha);
        })(),
        'proof no longer published' => (function () use ($alpha): void {
            $this->dns->answers = [];
            expect(isolationVerify($alpha))->toBeFalse();
        })(),
        'lease expired at its exact end' => $this->travel(24)->hours(),
        'claim removed' => $domains->save($alpha->team->owner, $alpha->team->id, $alpha->page->id, null),
        'claim replaced' => isolationClaim($alpha, 'replacement.alpha.example.com'),
        'page returned to draft' => isolationChangePage($alpha, ['isPublic' => false]),
        'page deleted' => app(TenantContext::class)->run($alpha->team->id, static fn () => $alpha->page->delete()),
    };

    $requests = [
        ['GET', '/', []],
        ['GET', '/status/alpha-status', []],
        ['HEAD', '/status/alpha-status', []],
        ['POST', '/status/alpha-status/unlock', ['password' => 'anything']],
        ['POST', '/status/alpha-status/subscribe', ['email' => 'reader@example.test']],
    ];

    foreach ($requests as [$method, $path, $data]) {
        $response = $this->call($method, 'https://'.ISOLATION_HOST.$path, $data)->assertNotFound();

        // Refused before sessions: no cookie, and nothing about the page in the answer.
        expect($response->headers->getCookies())->toBeEmpty()
            ->and($response->getContent())->not->toContain('Alpha status');
    }

    expect(DB::table('status_page_subscribers')->count())->toBe(0);

    $this->get('http://localhost/internal/status-domains/allow?domain='.ISOLATION_HOST)->assertNotFound();
})->with([
    'never proved',
    'proof no longer published',
    'lease expired at its exact end',
    'claim removed',
    'claim replaced',
    'page returned to draft',
    'page deleted',
]);

it('does not let a pending claim of another tenant route, reserve, reveal or revoke a live domain', function (): void {
    $alpha = $this->alpha;
    $beta = $this->beta;

    $claim = isolationClaim($beta);
    $owner = isolationFreshPage($alpha);

    // The pending claim changes nothing for visitors or certificates, and serves only the first tenant's page.
    isolationAlias('GET', '/status/beta-status')->assertNotFound();
    isolationAlias('GET', '/status/alpha-status')->assertOk();
    $this->get('http://localhost/internal/status-domains/allow?domain='.ISOLATION_HOST)->assertNoContent();

    // Each settings page shows only its own proof generation.
    $alphaSettings = $this->actingAs($alpha->team->owner)->get("http://localhost/teams/{$alpha->team->id}/status-pages")->assertOk();
    $betaSettings = $this->actingAs($beta->team->owner)->get("http://localhost/teams/{$beta->team->id}/status-pages")->assertOk();

    expect($alphaSettings->getContent())->toContain($owner->domain_challenge)
        ->not->toContain($claim->domain_challenge);
    expect($betaSettings->getContent())->toContain($claim->domain_challenge)
        ->not->toContain($owner->domain_challenge);

    // An absent proof and a hostname owned by someone else give the same refusal, so a claim does not reveal a customer.
    $verify = "http://localhost/teams/{$beta->team->id}/status-pages/{$beta->page->id}/domain/verify";

    $this->from("http://localhost/teams/{$beta->team->id}/status-pages")->post($verify)->assertRedirect();
    $absent = isolationSessionErrors();

    isolationProve($alpha, $beta);

    $this->from("http://localhost/teams/{$beta->team->id}/status-pages")->post($verify)->assertRedirect();
    $occupied = isolationSessionErrors();

    expect($absent)->not->toBeEmpty()
        ->and($occupied)->toBe($absent);

    // Removing the losing claim cannot release the winner's association.
    app(ManageStatusDomains::class)->save($beta->team->owner, $beta->team->id, $beta->page->id, null);

    expect(DB::table('status_domain_bindings')->where('hostname', ISOLATION_HOST)->value('team_id'))->toBe($alpha->team->id);
    isolationAlias('GET', '/status/alpha-status')->assertOk();
});

it('moves a hostname to a new verified owner without carrying sessions, pages or the power to revoke', function (): void {
    $alpha = $this->alpha;
    $beta = $this->beta;

    isolationChangePage($alpha, ['password' => ISOLATION_PASSWORD]);
    isolationChangePage($beta, ['password' => ISOLATION_PASSWORD]);

    isolationAlias('POST', '/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();

    $first = isolationAlias('GET', '/status/alpha-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Public'));

    $this->travel(24)->hours();

    isolationClaim($beta);
    isolationProve($beta);

    expect(isolationVerify($beta))->toBeTrue();

    // The browser still sends the first owner's cookie: it has another name, so the same password stays unentered here.
    isolationAlias('GET', '/')->assertRedirect('https://'.ISOLATION_HOST.'/status/beta-status');
    isolationAlias('GET', '/status/alpha-status')->assertNotFound();
    isolationAlias('POST', '/status/alpha-status/unlock', ['password' => ISOLATION_PASSWORD])->assertNotFound();
    isolationAlias('POST', '/status/alpha-status/subscribe', ['email' => 'reader@example.test'])->assertNotFound();

    $second = isolationAlias('GET', '/status/beta-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock'));

    expect(isolationSessionCookieName($second))->not->toBe(isolationSessionCookieName($first));

    // The previous owner cannot win back or revoke the name, with its proof absent or still published.
    expect(isolationVerify($alpha))->toBeFalse();

    isolationProve($alpha, $beta);

    expect(isolationVerify($alpha))->toBeFalse();

    app(ManageStatusDomains::class)->save($alpha->team->owner, $alpha->team->id, $alpha->page->id, null);

    expect(DB::table('status_domain_bindings')->where('hostname', ISOLATION_HOST)->value('team_id'))->toBe($beta->team->id)
        ->and(DB::table('status_page_subscribers')->count())->toBe(0);

    $this->get('http://localhost/internal/status-domains/allow?domain='.ISOLATION_HOST)->assertNoContent();
    isolationAlias('POST', '/status/beta-status/unlock', ['password' => ISOLATION_PASSWORD])->assertRedirect();
    isolationAlias('GET', '/status/beta-status')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('StatusPages/Public')
        ->where('page.title', 'Beta status'));
});

it('closes every public surface of a deleted team and frees its hostname, leaving the other tenant intact', function (): void {
    $alpha = $this->alpha;
    $beta = $this->beta;

    $subscriber = isolationSubscriber($alpha, 'reader@example.test');
    $survivor = isolationSubscriber($beta, 'reader@example.test');
    $link = app(StatusSubscriptionLinks::class)->action($subscriber, 'unsubscribe');
    $path = (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);

    $this->get('http://localhost/status/alpha-status')->assertOk();

    DB::table('teams')->where('id', $alpha->team->id)->delete();

    $this->get('http://localhost/status/alpha-status')->assertNotFound();
    $this->post('http://localhost'.$path)->assertNotFound();
    $this->call('GET', 'https://'.ISOLATION_HOST.'/status/alpha-status')->assertNotFound();
    $this->get('http://localhost/internal/status-domains/allow?domain='.ISOLATION_HOST)->assertNotFound();

    expect(DB::table('status_domain_bindings')->count())->toBe(0)
        ->and(DB::table('status_page_subscribers')->where('team_id', $alpha->team->id)->count())->toBe(0)
        ->and(DB::table('status_page_subscribers')->where('id', $survivor->id)->count())->toBe(1);

    $this->get('http://localhost/status/beta-status')->assertOk();

    isolationClaim($beta);
    isolationProve($beta);

    expect(isolationVerify($beta))->toBeTrue();
});
