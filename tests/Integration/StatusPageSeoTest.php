<?php

declare(strict_types=1);

/** Verify crawler-visible HTML, canonical origins and restricted metadata against real PostgreSQL projections. */

use App\Actions\Incidents\ManageIncidents;
use App\Actions\StatusPages\ManageStatusDomains;
use App\Actions\StatusPages\ManageStatusPages;
use App\Contracts\Monitoring\DnsTransport;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageMetadata;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Services\StatusPages\StatusSubscriptionLinks;
use App\Services\StatusPages\StatusSubscriptions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use Tests\Support\FakeDnsTransport;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    // Rendering without Vite does not disable Inertia's independent client-version check.
    $this->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '');
    $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    config(['app.name' => 'Signalbox', 'app.url' => 'https://app.signalbox.com']);
    Cache::flush();
    $this->team = Team::factory()->create(['name' => 'Private tenant']);
    $this->monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create(['name' => 'internal-database']));
    $this->page = app(ManageStatusPages::class)->create($this->team->owner, $this->team->id, seoPageInput($this->monitor));
});

/**
 * @param Monitor $monitor Private monitor represented by a public component label.
 * @param array<string, mixed> $overrides Changes applied through the real management boundary.
 * @return StatusPageInput Complete page input, suitable for creation or update.
 */
function seoPageInput(Monitor $monitor, array $overrides = []): StatusPageInput
{
    return new StatusPageInput(...[
        'slug' => 'seo-status',
        'title' => 'Acme status',
        'description' => 'Current service availability.',
        'theme' => new StatusPageTheme(StatusPageThemeMode::System, StatusPageAccent::Emerald),
        'isPublic' => true,
        'password' => null,
        'removePassword' => false,
        'components' => [['name' => 'Public API', 'monitor_ids' => [$monitor->id]]],
        ...$overrides,
    ]);
}

/**
 * @param string $html Actual initial document, not an Inertia JSON response.
 * @return DOMXPath Parsed document; XPath assertions explicitly select head or visible snapshot nodes.
 */
function seoDocument(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);

    try {
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    return new DOMXPath($document);
}

/**
 * @param DOMXPath $document Parsed initial document.
 * @param string $selector XPath string expression or node selector.
 * @return string Text or attribute value, independent of escaped data-page JSON.
 */
function seoText(DOMXPath $document, string $selector): string
{
    return (string) $document->evaluate('string('.$selector.')');
}

/**
 * @param DOMXPath $document Parsed initial document.
 * @return string Complete head markup including attributes, excluding serialized Inertia body props.
 */
function seoHead(DOMXPath $document): string
{
    $head = $document->query('//head')->item(0);

    return $head === null ? '' : (string) $document->document->saveHTML($head);
}

it('renders complete unique metadata in the initial head without JavaScript or a build', function (): void {
    $response = $this->get('/status/seo-status?campaign=test')->assertOk()
        ->assertHeader('X-Robots-Tag', StatusPageMetadata::PUBLIC_ROBOTS)
        ->assertHeader('Link', '<https://app.signalbox.com/status/seo-status>; rel="canonical"');
    $document = seoDocument($response->getContent());

    expect(seoText($document, '//head/title'))->toBe('Acme status | Signalbox')
        ->and(seoText($document, '//head/meta[@name="description"]/@content'))->toBe('Current service availability.')
        ->and(seoText($document, '//head/meta[@name="robots"]/@content'))->toBe(StatusPageMetadata::PUBLIC_ROBOTS)
        ->and(seoText($document, '//head/link[@rel="canonical"]/@href'))->toBe('https://app.signalbox.com/status/seo-status')
        ->and(seoText($document, '//head/meta[@property="og:url"]/@content'))->toBe('https://app.signalbox.com/status/seo-status')
        ->and(seoText($document, '//head/meta[@property="og:title"]/@content'))->toBe('Acme status | Signalbox')
        ->and(seoText($document, '//head/meta[@property="og:description"]/@content'))->toBe('Current service availability.')
        ->and(seoText($document, '//head/meta[@property="og:type"]/@content'))->toBe('website')
        ->and(seoText($document, '//head/meta[@property="og:site_name"]/@content'))->toBe('Signalbox')
        ->and(seoText($document, '//head/meta[@name="twitter:card"]/@content'))->toBe('summary')
        ->and(seoText($document, '//head/meta[@name="twitter:title"]/@content'))->toBe('Acme status | Signalbox')
        ->and(seoText($document, '//head/meta[@name="twitter:description"]/@content'))->toBe('Current service availability.')
        ->and($document->evaluate('count(//head/title)'))->toBe(1.0)
        ->and($document->evaluate('count(//head/meta[@name="description"])'))->toBe(1.0)
        ->and($document->evaluate('count(//div[@id="app"]/@data-page)'))->toBe(1.0);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('escapes literal text in head and visible content instead of interpreting markup', function (): void {
    $title = 'Caffè <script>alert("title")</script> & API';
    $description = "Availability \"quoted\" & <img src=x onerror=alert(1)>\nSecond line.";
    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, seoPageInput($this->monitor, compact('title', 'description')));
    $document = seoDocument($this->get('/status/seo-status')->assertOk()->getContent());

    expect(seoText($document, '//head/title'))->toBe($title.' | Signalbox')
        ->and(seoText($document, '//head/meta[@name="description"]/@content'))->toBe(str_replace("\n", ' ', $description))
        ->and(seoText($document, '//main[@id="status-page-snapshot"]/h1'))->toBe($title)
        ->and($document->evaluate('count(//script | //img)'))->toBe(0.0);
});

it('provides a useful plain text description when the optional introduction is absent', function (): void {
    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, seoPageInput($this->monitor, ['description' => null]));
    $document = seoDocument($this->get('/status/seo-status')->assertOk()->getContent());

    expect(seoText($document, '//head/meta[@name="description"]/@content'))
        ->toBe('Service status, availability history and published incident updates for Acme status.');
});

it('renders public component states and only explicitly published incident content outside the Inertia root', function (): void {
    $entry = app(TenantContext::class)->run($this->team->id, function (): IncidentUpdate {
        $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id, 'root_cause_note' => 'Secret cause']);
        IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'message' => 'Unpublished internal update']);

        return IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'message' => 'We are investigating <the API>.']);
    });
    app(ManageIncidents::class)->publish($this->team->owner, $this->team->id, $entry->id);
    $document = seoDocument($this->get('/status/seo-status')->assertOk()->getContent());
    $body = seoText($document, '//main[@id="status-page-snapshot"]');

    expect($body)->toContain('Acme status', 'Public API', 'No uptime data', 'We are investigating <the API>.', '2026-11-15 12:00 UTC')
        ->not->toContain('Unpublished internal update', 'Secret cause', 'internal-database', 'Private tenant', $this->team->owner->email)
        ->and($document->evaluate('count(//div[@id="app"]//main)'))->toBe(0.0)
        ->and(seoText($document, '//main//time/@datetime'))->toBe('2026-11-15T12:00:00Z');
});

it('includes observed uptime from the same daily rollup projection as the interactive page', function (): void {
    DB::table('check_rollups_daily')->insert([
        'team_id' => $this->team->id,
        'monitor_id' => $this->monitor->id,
        'day' => '2026-11-14',
        'hours' => 24,
        'checks' => 0,
        'failures' => 0,
        'unknown_checks' => 0,
        'up_us' => 3_600_000_000,
        'down_us' => 3_600_000_000,
        'unknown_us' => 0,
        'excluded_us' => 0,
        'latency_samples' => 0,
        'latency_sum_ms' => 0,
        'latency_counts' => '{}',
    ]);
    $document = seoDocument($this->get('/status/seo-status')->assertOk()->getContent());

    expect(seoText($document, '//main[@id="status-page-snapshot"]'))
        ->toContain('50% observed uptime in the last 90 days, excluding time without data.');
});

it('keeps password pages generic and noindex both before and after unlocking with a warm public cache', function (): void {
    $this->get('/status/seo-status')->assertOk();
    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, seoPageInput($this->monitor, ['password' => 'correct-password']));

    foreach ([false, true] as $unlocked) {
        if ($unlocked) {
            $this->post('/status/seo-status/unlock', ['password' => 'correct-password'])->assertRedirect()
                ->assertHeader('X-Robots-Tag', StatusPageMetadata::PRIVATE_ROBOTS)->assertHeaderMissing('Link');
        }

        $response = $this->get('/status/seo-status')->assertOk()
            ->assertHeader('X-Robots-Tag', StatusPageMetadata::PRIVATE_ROBOTS)->assertHeaderMissing('Link');
        $document = seoDocument($response->getContent());

        expect(seoText($document, '//head/title'))->toBe('Protected status page | Signalbox')
            ->and(seoText($document, '//head/meta[@name="robots"]/@content'))->toBe(StatusPageMetadata::PRIVATE_ROBOTS)
            ->and(seoHead($document))->not->toContain('Acme', 'Public API', 'Current service')
            ->and($document->evaluate('count(//head/link[@rel="canonical"] | //head/meta[starts-with(@property,"og:")] | //head/meta[starts-with(@name,"twitter:")] | //main[@id="status-page-snapshot"])'))->toBe(0.0);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component($unlocked ? 'StatusPages/Public' : 'StatusPages/Unlock'));
    }

    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, seoPageInput($this->monitor, ['removePassword' => true]));
    $this->get('/status/seo-status')->assertHeader('X-Robots-Tag', StatusPageMetadata::PUBLIC_ROBOTS)
        ->assertSee('id="status-page-snapshot"', false);
});

it('refreshes metadata even when an Inertia partial visit asks for other props only', function (): void {
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'StatusPages/Public', 'X-Inertia-Partial-Data' => 'subscribePath'];
    $this->get('/status/seo-status', $headers)->assertOk()->assertJsonPath('props.metadata.canonicalUrl', 'https://app.signalbox.com/status/seo-status')
        ->assertHeader('X-Robots-Tag', StatusPageMetadata::PUBLIC_ROBOTS)->assertJsonMissingPath('props.page');
    app(ManageStatusPages::class)->update($this->team->owner, $this->team->id, $this->page->id, seoPageInput($this->monitor, ['password' => 'correct-password']));
    $this->post('/status/seo-status/unlock', ['password' => 'correct-password'])->assertRedirect();
    $this->get('/status/seo-status', $headers)->assertOk()->assertJsonPath('props.metadata.canonicalUrl', null)
        ->assertJsonPath('props.metadata.robots', StatusPageMetadata::PRIVATE_ROBOTS)
        ->assertHeader('X-Robots-Tag', StatusPageMetadata::PRIVATE_ROBOTS)->assertHeaderMissing('Link');
});

it('uses the configured primary canonical on verified aliases and ignores queries and forwarded hosts', function (): void {
    $dns = new FakeDnsTransport();
    $this->app->instance(DnsTransport::class, $dns);
    $domains = app(ManageStatusDomains::class);
    $domains->save($this->team->owner, $this->team->id, $this->page->id, 'status.example.com');
    $page = app(TenantContext::class)->run($this->team->id, fn (): StatusPage => $this->page->fresh());
    $name = '_signalbox.status.example.com.';
    $dns->answers[$name.'|'.Message::TYPE_TXT] = Message::createResponseWithAnswersForQuery(new Query($name, Message::TYPE_TXT, Message::CLASS_IN), [
        new Record($name, Message::TYPE_TXT, Message::CLASS_IN, 60, ['signalbox-verification='.$page->domain_challenge]),
    ]);
    expect($domains->verify($this->team->owner, $this->team->id, $page->id))->toBeTrue();

    foreach (['https://app.signalbox.com', 'https://status.example.com'] as $origin) {
        $response = $this->get($origin.'/status/seo-status?redirect=evil', ['X-Forwarded-Host' => 'evil.example.com'])->assertOk()
            ->assertHeader('Link', '<https://app.signalbox.com/status/seo-status>; rel="canonical"');
        expect(seoText(seoDocument($response->getContent()), '//head/link[@rel="canonical"]/@href'))->toBe('https://app.signalbox.com/status/seo-status');
    }

    $this->travel(24)->hours();
    $this->get('https://status.example.com/status/seo-status')->assertNotFound()->assertDontSee('status-page-snapshot');
    $this->get('https://app.signalbox.com/status/seo-status')->assertOk()->assertHeader('X-Robots-Tag', StatusPageMetadata::PUBLIC_ROBOTS);
});

it('keeps signed email capabilities generic and noindex without leaking the capability into head tags', function (string $action): void {
    app(StatusSubscriptions::class)->subscribe($this->page, app('session.store'), 'reader@example.test');
    $subscriber = app(TenantContext::class)->run($this->team->id, fn (): StatusPageSubscriber => StatusPageSubscriber::query()->firstOrFail());
    $link = app(StatusSubscriptionLinks::class)->action($subscriber, $action);

    foreach (['GET', 'POST'] as $method) {
        $response = $this->call($method, $link)->assertOk()->assertHeader('X-Robots-Tag', StatusPageMetadata::PRIVATE_ROBOTS)->assertHeaderMissing('Link');
        $document = seoDocument($response->getContent());
        expect(seoText($document, '//head/meta[@name="robots"]/@content'))->toBe(StatusPageMetadata::PRIVATE_ROBOTS)
            ->and(seoHead($document))->not->toContain('Acme', 'reader@example.test', $subscriber->generation, 'signature=')
            ->and($document->evaluate('count(//head/link[@rel="canonical"] | //head/meta[@property="og:url"] | //main[@id="status-page-snapshot"])'))->toBe(0.0);
    }
})->with(['verify', 'unsubscribe']);

it('provides the same public metadata and summary to crawlers and other visitors', function (): void {
    $normal = seoDocument($this->get('/status/seo-status', ['User-Agent' => 'Ordinary browser'])->assertOk()->getContent());
    $bot = seoDocument($this->get('/status/seo-status', ['User-Agent' => 'Googlebot'])->assertOk()->getContent());

    expect(seoText($normal, '//head/title'))->toBe(seoText($bot, '//head/title'))
        ->and(seoText($normal, '//main[@id="status-page-snapshot"]'))->toBe(seoText($bot, '//main[@id="status-page-snapshot"]'));
});

it('returns canonical headers for HEAD and no public document for drafts or unknown hosts', function (): void {
    $this->head('/status/seo-status')->assertOk()->assertHeader('X-Robots-Tag', StatusPageMetadata::PUBLIC_ROBOTS)
        ->assertHeader('Link', '<https://app.signalbox.com/status/seo-status>; rel="canonical"')->assertContent('');
    DB::table('status_pages')->where('id', $this->page->id)->update(['is_public' => false]);
    $this->get('/status/seo-status')->assertNotFound()->assertDontSee('status-page-snapshot')->assertHeaderMissing('Link');
    $this->get('https://unknown.example.com/status/seo-status')->assertNotFound()->assertDontSee('Acme')->assertHeaderMissing('Link');
});

it('defaults status route failures to noindex without a canonical header', function (): void {
    $this->post('/status/seo-status/subscribe', ['email' => 'invalid'])->assertRedirect()
        ->assertHeader('X-Robots-Tag', StatusPageMetadata::PRIVATE_ROBOTS)->assertHeaderMissing('Link');
    $this->get('/status/missing-page')->assertNotFound()
        ->assertHeader('X-Robots-Tag', StatusPageMetadata::PRIVATE_ROBOTS)->assertHeaderMissing('Link');
    $this->get('/status/subscriptions/1/1/1/00000000-0000-4000-8000-000000000000/verify?signature=wrong')->assertForbidden()
        ->assertHeader('X-Robots-Tag', StatusPageMetadata::PRIVATE_ROBOTS)->assertHeaderMissing('Link');
});
