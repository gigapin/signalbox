<?php

declare(strict_types=1);

/**
 * Check the browser security headers added to every response by SetSecurityHeaders.
 *
 * Covers private, public, JSON and error responses on the primary origin, the per-request nonce shared with the
 * published error layout and Horizon, HSTS, report-only mode under debug, the Reverb and Vite development sources,
 * and a malformed Host header. Custom status domains are covered in StatusDomainsTest.
 */

use App\Http\Middleware\SetSecurityHeaders;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    Cache::flush();
    config(['app.debug' => false]);
});

/**
 * Assert the headers every response must carry and return the nonce of its enforced policy.
 *
 * @param TestResponse $response Response under test.
 * @return string Nonce found in the policy's script-src directive.
 */
function expectSecurityHeaders(TestResponse $response): string
{
    $policy = (string) $response->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain("'unsafe-inline'")
        ->not->toContain("'unsafe-eval'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Permissions-Policy'))->toBe(SetSecurityHeaders::PERMISSIONS_POLICY)
        ->and($response->headers->get('Referrer-Policy'))->not->toBeNull();

    expect(preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $matches))->toBe(1);

    return $matches[1];
}

it('protects private, public, JSON and error responses on the primary origin', function (): void {
    $user = User::factory()->create();

    $responses = [
        'home' => $this->get('/'),
        'login' => $this->get('/login'),
        'json' => $this->postJson('/forgot-password', ['email' => 'nobody@example.test']),
        'missing' => $this->get('/no-such-page'),
        'account' => $this->actingAs($user)->get('/account'),
    ];

    expect($responses['home']->status())->toBe(200)
        ->and($responses['account']->status())->toBe(200)
        ->and($responses['json']->status())->toBe(200)
        ->and($responses['missing']->status())->toBe(404);

    foreach ($responses as $response) {
        expectSecurityHeaders($response);
    }

    expect($responses['account']->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($responses['home']->headers->get('Referrer-Policy'))->toBe(SetSecurityHeaders::REFERRER_POLICY);
});

it('shares a fresh nonce with the inline styles of the published error layout', function (): void {
    $first = $this->get('/no-such-page')->assertNotFound();
    $second = $this->get('/no-such-page')->assertNotFound();

    $nonce = expectSecurityHeaders($first);

    expect(substr_count($first->getContent(), '<style nonce="'.$nonce.'">'))->toBe(2)
        ->and(expectSecurityHeaders($second))->not->toBe($nonce);
});

it('sends HSTS only on the primary origin over HTTPS and without subdomains', function (): void {
    $secure = $this->get('https://localhost/');
    $plain = $this->get('http://localhost/');

    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('reports instead of enforcing the policy while debug pages are enabled', function (): void {
    config(['app.debug' => true]);

    $response = $this->get('/');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and((string) $response->headers->get('Content-Security-Policy-Report-Only'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('allows only the configured browser Reverb endpoint for WebSocket connections', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.browser.host' => null,
        'broadcasting.browser.port' => 8080,
        'broadcasting.browser.scheme' => 'http',
    ]);

    expect($this->get('http://localhost/')->headers->get('Content-Security-Policy'))
        ->toEndWith("connect-src 'self' ws://localhost:8080");

    config([
        'broadcasting.browser.host' => 'ws.signalbox.example',
        'broadcasting.browser.port' => 443,
        'broadcasting.browser.scheme' => 'https',
    ]);

    expect($this->get('http://localhost/')->headers->get('Content-Security-Policy'))
        ->toEndWith("connect-src 'self' wss://ws.signalbox.example:443");

    config(['broadcasting.default' => 'log']);

    expect($this->get('http://localhost/')->headers->get('Content-Security-Policy'))
        ->toEndWith("connect-src 'self'");
});

it('widens the policy only for the Horizon dashboard and gives its inline assets the nonce', function (): void {
    $operator = User::factory()->platformAdmin()->create();

    $horizon = $this->actingAs($operator)->get('/horizon')->assertOk();
    $policy = (string) $horizon->headers->get('Content-Security-Policy');

    expect(preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $matches))->toBe(1);

    expect($policy)->toContain("'unsafe-eval'")
        ->toContain('font-src \'self\' https://fonts.bunny.net')
        ->and($horizon->getContent())->toContain('<script type="module" nonce="'.$matches[1].'">')
        ->and(substr_count($horizon->getContent(), 'nonce="'.$matches[1].'"'))->toBe(4);

    expectSecurityHeaders($this->get('/account'));
});

it('allows the Vite development server only while its hot file exists', function (): void {
    $hot = tempnam(sys_get_temp_dir(), 'vite-hot-');
    file_put_contents($hot, 'http://127.0.0.1:5173');

    // The fake installed by withoutVite() ignores hot files; in hot mode the real one needs no build manifest.
    $this->withVite();
    app(Vite::class)->useHotFile($hot);

    try {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

        expect($policy)->toContain("script-src 'self' 'nonce-")
            ->toContain('http://127.0.0.1:5173')
            ->toContain("'unsafe-inline'")
            ->toContain('ws://127.0.0.1:5173');
    } finally {
        unlink($hot);
        $this->withoutVite();
    }

    expect((string) $this->get('/')->headers->get('Content-Security-Policy'))->not->toContain('5173');
});

it('still adds the headers when a malformed Host header is refused', function (): void {
    // The test client cannot build a malformed URI, so the raw Host header is set on a request passed to the kernel.
    $request = Request::create('/');
    $request->headers->set('Host', 'bad host');

    // The framework already answered 400 before these headers existed; only the headers are new.
    $response = TestResponse::fromBaseResponse(app(Kernel::class)->handle($request))->assertBadRequest();

    expectSecurityHeaders($response);
    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});
