<?php

declare(strict_types=1);

/** Verify access-time destination authorization, redirect hops and pinned transport options with fake DNS. */

use App\Enums\Security\OutboundRejection;
use App\Exceptions\OutboundDestinationRejected;
use App\Services\Monitoring\MonitorInputLimits;
use App\Services\Monitoring\MonitorInputSyntax;
use App\Services\Security\GuardedHttpOptions;
use App\Services\Security\OutboundAddressPolicy;
use App\Services\Security\OutboundDestinationGuard;
use App\Services\Security\RedirectPolicy;
use Illuminate\Config\Repository;
use Tests\Support\FakeHostResolver;

beforeEach(function (): void {
    $this->resolver = new FakeHostResolver([
        'public.example' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
        'other.example' => ['93.184.215.15'],
        'mixed.example' => ['93.184.215.14', '10.0.0.5'],
        'rebind.example' => ['169.254.169.254'],
        'mapped.example' => ['::ffff:127.0.0.1'],
        'xn--bcher-kva.example' => ['93.184.215.16'],
        'garbage.example' => ['not-an-address'],
    ]);

    $config = new Repository([
        'monitor-input' => require __DIR__.'/../../config/monitor-input.php',
        'outbound-security' => ['allowed_cidrs' => [], 'max_redirects' => 2],
    ]);

    $this->guard = new OutboundDestinationGuard(
        new OutboundAddressPolicy($config),
        $this->resolver,
        new MonitorInputSyntax(new MonitorInputLimits($config)),
    );

    $this->redirects = new RedirectPolicy($this->guard, $config);
});

/**
 * @param Closure(): mixed $action Guarded operation expected to fail.
 * @return OutboundRejection Reason carried by the rejection.
 */
function rejectionOf(Closure $action): OutboundRejection
{
    try {
        $action();
    } catch (OutboundDestinationRejected $exception) {
        return $exception->reason;
    }

    throw new LogicException('The guarded operation was not rejected.');
}

it('authorizes public URLs with every resolved address pinned and default ports applied', function (): void {
    $https = $this->guard->authorizeUrl('HTTPS://Public.Example/path?token=secret');
    $http = $this->guard->authorizeUrl('http://public.example:8080');

    expect($https->scheme)->toBe('https')
        ->and($https->host)->toBe('public.example')
        ->and($https->port)->toBe(443)
        ->and($https->addresses)->toBe(['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'])
        ->and($https->url)->toBe('https://public.example/path?token=secret')
        ->and($http->port)->toBe(8080)
        ->and($this->guard->authorizeUrl('http://public.example')->port)->toBe(80);
});

it('authorizes IDNA hosts and public IP literals without a DNS query', function (): void {
    expect($this->guard->authorizeUrl('https://bücher.example/')->addresses)->toBe(['93.184.215.16']);

    $this->resolver->queries = [];
    $literal = $this->guard->authorizeUrl('http://[2606:4700:4700::1111]:8443/');

    expect($literal->host)->toBe('2606:4700:4700::1111')
        ->and($literal->addresses)->toBe(['2606:4700:4700::1111'])
        ->and($literal->isIpLiteral())->toBeTrue()
        ->and($this->resolver->queries)->toBe([]);
});

it('refuses destinations before any connection with a stable reason', function (string $url, OutboundRejection $reason): void {
    expect(rejectionOf(fn () => $this->guard->authorizeUrl($url)))->toBe($reason);
})->with([
    'any blocked answer' => ['https://mixed.example/', OutboundRejection::BlockedAddress],
    'metadata through DNS' => ['http://rebind.example/', OutboundRejection::BlockedAddress],
    'mapped loopback through DNS' => ['http://mapped.example/', OutboundRejection::BlockedAddress],
    'malformed answer' => ['http://garbage.example/', OutboundRejection::BlockedAddress],
    'no answer' => ['http://unknown.example/', OutboundRejection::ResolutionFailed],
    'loopback literal' => ['http://127.0.0.1/', OutboundRejection::BlockedAddress],
    'metadata literal' => ['http://169.254.169.254/latest/meta-data/', OutboundRejection::BlockedAddress],
    'IPv6 loopback literal' => ['http://[::1]/', OutboundRejection::BlockedAddress],
    'mapped literal' => ['http://[::ffff:10.0.0.1]/', OutboundRejection::BlockedAddress],
    'localhost' => ['http://localhost:8000/', OutboundRejection::LocalName],
    'localhost subdomain' => ['http://api.LOCALHOST./', OutboundRejection::LocalName],
    'userinfo' => ['https://user:pass@public.example/', OutboundRejection::InvalidDestination],
    'non-HTTP scheme' => ['file:///etc/passwd', OutboundRejection::InvalidDestination],
    'gopher scheme' => ['gopher://public.example/', OutboundRejection::InvalidDestination],
    'decimal IPv4 host' => ['http://2130706433/', OutboundRejection::InvalidDestination],
    'octal IPv4 host' => ['http://0177.0.0.1/', OutboundRejection::InvalidDestination],
    'fragment' => ['https://public.example/#x', OutboundRejection::InvalidDestination],
]);

it('authorizes TLS endpoints and custom DNS resolvers with the same address policy', function (): void {
    $tls = $this->guard->authorizeEndpoint('Public.Example.', 8443);
    $resolver = $this->guard->authorizeResolver('2606:4700:4700:0:0:0:0:1111');

    expect($tls->scheme)->toBeNull()
        ->and($tls->host)->toBe('public.example')
        ->and($tls->port)->toBe(8443)
        ->and($tls->url)->toBeNull()
        ->and($resolver->host)->toBe('2606:4700:4700::1111')
        ->and($resolver->port)->toBe(53)
        ->and(rejectionOf(fn () => $this->guard->authorizeEndpoint('rebind.example', 443)))->toBe(OutboundRejection::BlockedAddress)
        ->and(rejectionOf(fn () => $this->guard->authorizeEndpoint('public.example', 0)))->toBe(OutboundRejection::InvalidDestination)
        ->and(rejectionOf(fn () => $this->guard->authorizeEndpoint('bad_host', 443)))->toBe(OutboundRejection::InvalidDestination)
        ->and(rejectionOf(fn () => $this->guard->authorizeResolver('127.0.0.53')))->toBe(OutboundRejection::BlockedAddress)
        ->and(rejectionOf(fn () => $this->guard->authorizeResolver('resolver.example')))->toBe(OutboundRejection::InvalidDestination);
});

it('never places destinations or addresses in rejection messages', function (): void {
    foreach (OutboundRejection::cases() as $reason) {
        $message = (new OutboundDestinationRejected($reason))->getMessage();

        expect($message)->not->toMatch('/\d+\.\d+\.\d+\.\d+|::|https?:\/\//');
    }
});

it('hides the private URL from debug output', function (): void {
    $dump = print_r($this->guard->authorizeUrl('https://public.example/?token=secret'), true);

    expect($dump)->not->toContain('secret')->toContain('[REDACTED]');
});

it('builds pinned Guzzle options without redirects or environment proxies', function (): void {
    $options = (new GuardedHttpOptions())->for($this->guard->authorizeUrl('https://public.example/'));

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['public.example:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'])
        ->and($options['curl'][CURLOPT_PROXY])->toBe('')
        ->and($options['curl'][CURLOPT_NOPROXY])->toBe('*')
        ->and($options['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTP | CURLPROTO_HTTPS)
        ->and($options['curl'][CURLOPT_REDIR_PROTOCOLS])->toBe(CURLPROTO_HTTP | CURLPROTO_HTTPS);

    $literal = (new GuardedHttpOptions())->for($this->guard->authorizeUrl('http://93.184.215.14/'));

    expect($literal['curl'])->not->toHaveKey(CURLOPT_RESOLVE);
});

it('resolves relative and absolute redirects and re-authorizes every hop', function (string $location, string $expected, bool $crossOrigin): void {
    $current = $this->guard->authorizeUrl('https://public.example/a/b?q=1');
    $step = $this->redirects->next($current, $location, 0);

    expect($step->destination->url)->toBe($expected)
        ->and($step->crossOrigin)->toBe($crossOrigin);
})->with([
    'absolute path' => ['/login', 'https://public.example/login', false],
    'relative path' => ['c?x=2', 'https://public.example/a/c?x=2', false],
    'query only' => ['?page=2', 'https://public.example/a/b?page=2', false],
    'fragment dropped' => ['/next#top', 'https://public.example/next', false],
    'scheme-relative' => ['//other.example/x', 'https://other.example/x', true],
    'absolute other host' => ['https://other.example/', 'https://other.example/', true],
    'port change' => ['https://public.example:8443/', 'https://public.example:8443/', true],
]);

it('forwards configured headers only on same-origin redirects', function (): void {
    $current = $this->guard->authorizeUrl('https://public.example/');
    $headers = ['authorization' => 'Bearer secret', 'cookie' => 'session=secret', 'x-api-key' => 'secret'];

    expect($this->redirects->next($current, '/next', 0)->headers($headers))->toBe($headers)
        ->and($this->redirects->next($current, 'https://other.example/', 0)->headers($headers))->toBe([]);
});

it('refuses redirects to blocked targets, downgrades, invalid schemes and past the hop limit', function (): void {
    $current = $this->guard->authorizeUrl('https://public.example/');

    expect(rejectionOf(fn () => $this->redirects->next($current, 'http://169.254.169.254/latest/', 0)))->toBe(OutboundRejection::BlockedAddress)
        ->and(rejectionOf(fn () => $this->redirects->next($current, 'https://rebind.example/', 0)))->toBe(OutboundRejection::BlockedAddress)
        ->and(rejectionOf(fn () => $this->redirects->next($current, 'http://localhost/', 0)))->toBe(OutboundRejection::LocalName)
        ->and(rejectionOf(fn () => $this->redirects->next($current, 'http://public.example/', 0)))->toBe(OutboundRejection::RedirectDowngrade)
        ->and(rejectionOf(fn () => $this->redirects->next($current, 'file:///etc/passwd', 0)))->toBe(OutboundRejection::InvalidDestination)
        ->and(rejectionOf(fn () => $this->redirects->next($current, 'https://u:p@other.example/', 0)))->toBe(OutboundRejection::InvalidDestination)
        ->and(rejectionOf(fn () => $this->redirects->next($current, '', 0)))->toBe(OutboundRejection::InvalidDestination)
        ->and($this->redirects->next($current, '/one', 1)->destination->url)->toBe('https://public.example/one')
        ->and(rejectionOf(fn () => $this->redirects->next($current, '/two', 2)))->toBe(OutboundRejection::RedirectLimit);

    $http = $this->guard->authorizeUrl('http://public.example/');

    expect($this->redirects->next($http, 'https://public.example/', 0)->crossOrigin)->toBeTrue();

    $endpoint = $this->guard->authorizeEndpoint('public.example', 443);

    expect(rejectionOf(fn () => $this->redirects->next($endpoint, '/x', 0)))->toBe(OutboundRejection::InvalidDestination);
});

it('rejects an invalid redirect limit configuration', function (mixed $maximum): void {
    expect(fn () => new RedirectPolicy($this->guard, new Repository(['outbound-security' => ['max_redirects' => $maximum]])))
        ->toThrow(LogicException::class);
})->with([-1, '5', null]);
