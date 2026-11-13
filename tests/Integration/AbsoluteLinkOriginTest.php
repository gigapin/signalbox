<?php

/**
 * Prove that absolute account links in email always target the primary origin.
 *
 * Password reset, email verification and team invitation links are generated from the incoming request. The
 * Symfony advisory GHSA-5mx8-mwrp-5693 describes generated URLs pointing at a client-chosen host, so these cases pin
 * the two application boundaries that keep that impossible: forwarded host headers are never trusted, even from an
 * explicit proxy peer, and GuardStatusPageHosts rejects unknown raw Host values before any route sends mail. Links
 * are read from mail rendered at send time by the array mailer, not recomputed later from a notification fake.
 */

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    Cache::flush();
    config(['mail.default' => 'array']);
});

afterEach(function (): void {
    TrustProxies::at([]);
});

/**
 * Headers an explicitly trusted proxy peer could forward for a hostile client.
 *
 * The scheme header is honoured by design and proves the peer was trusted; the host header must never be honoured.
 */
const HOSTILE_FORWARDED_HEADERS = [
    'X-Forwarded-Host' => 'attacker.example',
    'X-Forwarded-Proto' => 'https',
    'X-Forwarded-Port' => '443',
];

/**
 * Unknown raw Host values that must never reach a mail-sending route.
 *
 * The second value embeds the primary name as a prefix to catch prefix-based host comparisons.
 */
const UNKNOWN_HOSTS = [
    'attacker.example',
    'localhost.attacker.example',
];

/**
 * Collect every absolute URL from the text and HTML bodies of mail captured by the array transport.
 *
 * @return list<string> URLs in delivery order; empty when nothing was sent.
 * @throws LogicException When the configured mailer is not the in-memory array transport.
 */
function absoluteLinksInSentMail(): array
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    if (!$transport instanceof ArrayTransport) {
        throw new LogicException('The array mailer must capture rendered messages.');
    }

    $links = [];

    foreach ($transport->messages() as $sent) {
        $email = $sent->getOriginalMessage();

        if (!$email instanceof Email) {
            continue;
        }

        $bodies = array_filter(
            [$email->getTextBody(), $email->getHtmlBody()],
            is_string(...),
        );

        foreach ($bodies as $body) {
            preg_match_all('~https?://[^\s"\'<>()\[\]]+~', html_entity_decode($body), $matches);
            array_push($links, ...$matches[0]);
        }
    }

    return $links;
}

/**
 * Assert that sent mail holds the account link on the primary host over the forwarded HTTPS scheme.
 *
 * The primary host follows `app.url`, because the test client sends relative paths to that origin and its value
 * differs between the local `.env` and CI. Template links such as XHTML namespaces are ignored, but no captured link
 * may mention the forwarded host at all.
 *
 * @param string $path Path fragment identifying the account link under test.
 */
function expectOnlyPrimaryHttpsLinks(string $path): void
{
    $links = absoluteLinksInSentMail();
    $primaryHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    $accountLinks = array_filter(
        $links,
        fn (string $link): bool => str_contains($link, $path),
    );

    expect($accountLinks)->not->toBeEmpty()
        ->and(implode(' ', $links))->not->toContain(HOSTILE_FORWARDED_HEADERS['X-Forwarded-Host']);

    foreach ($accountLinks as $link) {
        expect(parse_url($link, PHP_URL_HOST))->toBe($primaryHost)
            ->and(parse_url($link, PHP_URL_SCHEME))->toBe('https');
    }
}

it('builds password reset links on the primary host despite forwarded host headers from a trusted proxy', function (): void {
    TrustProxies::at(['127.0.0.1']);
    $user = User::factory()->create();

    $this->postJson('/forgot-password', ['email' => $user->email], HOSTILE_FORWARDED_HEADERS)->assertOk();

    expectOnlyPrimaryHttpsLinks('/reset-password/');
});

it('builds verification links on the primary host despite forwarded host headers from a trusted proxy', function (): void {
    TrustProxies::at(['127.0.0.1']);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post('/email/verification-notification', [], HOSTILE_FORWARDED_HEADERS)
        ->assertRedirect();

    expectOnlyPrimaryHttpsLinks('/email/verify/');
});

it('builds invitation links on the primary host despite forwarded host headers from a trusted proxy', function (): void {
    TrustProxies::at(['127.0.0.1']);
    $team = Team::factory()->create();

    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/invitations", ['email' => 'invitee@example.test', 'role' => 'member'], HOSTILE_FORWARDED_HEADERS)
        ->assertRedirect("/teams/{$team->id}");

    expectOnlyPrimaryHttpsLinks('/invitations/');
});

it('rejects password reset requests on an unknown host before sending mail', function (string $host): void {
    $user = User::factory()->create();

    $this->postJson("http://{$host}/forgot-password", ['email' => $user->email])->assertNotFound();

    expect(absoluteLinksInSentMail())->toBe([]);
})->with(UNKNOWN_HOSTS);

it('rejects verification resends on an unknown host before sending mail', function (string $host): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post("http://{$host}/email/verification-notification")
        ->assertNotFound();

    expect(absoluteLinksInSentMail())->toBe([]);
})->with(UNKNOWN_HOSTS);

it('rejects invitations on an unknown host before writing or sending anything', function (string $host): void {
    $team = Team::factory()->create();

    $this->actingAs($team->owner)
        ->post("http://{$host}/teams/{$team->id}/invitations", ['email' => 'invitee@example.test', 'role' => 'member'])
        ->assertNotFound();

    $pending = app(TenantContext::class)->run(
        $team->id,
        fn (): int => TeamInvitation::query()->count(),
    );

    expect(absoluteLinksInSentMail())->toBe([])
        ->and($pending)->toBe(0);
})->with(UNKNOWN_HOSTS);
