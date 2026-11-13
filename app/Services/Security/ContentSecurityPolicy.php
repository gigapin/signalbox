<?php

declare(strict_types=1);

/** Build the Content-Security-Policy value for one response from the request and the runtime configuration. */

namespace App\Services\Security;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

/**
 * The policy is a second line of defence: React and Blade already escape output. Scripts and styles must come
 * from the application origin or carry the per-request nonce, which Laravel's Vite tags, the published error
 * layout and Horizon receive. Framing, plugins, `<base>` and cross-origin form posts are refused, and network
 * access is limited to the origin plus the configured browser Reverb endpoint.
 *
 * Two contexts widen it deliberately:
 * - Horizon, an operator-only page, loads fonts from fonts.bunny.net and its Vue bundle compiles templates with
 *   `new Function`, so it also gets `'unsafe-eval'`;
 * - the Vite development server (`public/hot`, local only) needs its own origin, WebSocket and injected styles.
 */
final readonly class ContentSecurityPolicy
{
    /** @var string Font host used by the Horizon dashboard layout. */
    private const string HORIZON_FONTS = 'https://fonts.bunny.net';

    /**
     * @param Repository $config Broadcasting and Horizon settings.
     * @param Vite $vite Development server detection.
     */
    public function __construct(private Repository $config, private Vite $vite)
    {
    }

    /**
     * @param Request $request Current request.
     * @param string|null $host Validated request hostname, the default WebSocket host; null when malformed.
     * @param string $nonce Base64 nonce shared with every inline script and style of this response.
     * @return string Header value with directives separated by `; `.
     */
    public function for(Request $request, ?string $host, string $nonce): string
    {
        $nonceSource = "'nonce-".$nonce."'";
        $scripts = ["'self'", $nonceSource];
        $styles = ["'self'", $nonceSource];
        $fonts = ["'self'"];
        $connections = ["'self'"];

        $websocket = $this->reverbOrigin($host);

        if ($websocket !== null) {
            $connections[] = $websocket;
        }

        if ($this->isHorizon($request)) {
            $scripts[] = "'unsafe-eval'";
            $styles[] = self::HORIZON_FONTS;
            $fonts[] = self::HORIZON_FONTS;
        }

        $development = $this->developmentServer();

        if ($development !== null) {
            $scripts[] = $development['http'];
            // Vite injects module CSS as nonce-less <style> elements while serving hot updates.
            $styles[] = $development['http'];
            $styles[] = "'unsafe-inline'";
            $connections[] = $development['http'];
            $connections[] = $development['ws'];
        }

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
            'script-src' => $scripts,
            'style-src' => $styles,
            'img-src' => ["'self'", 'data:'],
            'font-src' => $fonts,
            'connect-src' => $connections,
        ];

        $parts = [];

        foreach ($directives as $name => $sources) {
            $parts[] = $name.' '.implode(' ', array_values(array_unique($sources)));
        }

        return implode('; ', $parts);
    }

    /**
     * Mirrors HandleInertiaRequests::realtime(): a blank browser host means the page's own hostname.
     *
     * @param string|null $requestHost Validated request hostname, used when no browser host is configured.
     * @return string|null `ws://` or `wss://` origin of the browser Reverb endpoint, or null when Reverb is off or
     *                     no valid host is known.
     */
    private function reverbOrigin(?string $requestHost): ?string
    {
        if ($this->config->get('broadcasting.default') !== 'reverb') {
            return null;
        }

        $host = $this->config->get('broadcasting.browser.host');

        if (!is_string($host) || trim($host) === '') {
            $host = $requestHost;
        }

        if ($host === null || $host === '') {
            return null;
        }

        $port = $this->config->get('broadcasting.browser.port');
        $scheme = $this->config->get('broadcasting.browser.scheme') === 'https' ? 'wss' : 'ws';

        if (!is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }

        return $scheme.'://'.$host.':'.$port;
    }

    /**
     * @param Request $request Current request.
     * @return bool Whether the request targets the Horizon dashboard or its API.
     */
    private function isHorizon(Request $request): bool
    {
        $path = trim((string) $this->config->get('horizon.path', 'horizon'), '/');

        return $path !== '' && ($request->is($path) || $request->is($path.'/*'));
    }

    /**
     * @return array{http: string, ws: string}|null Origins of a running Vite development server, or null.
     */
    private function developmentServer(): ?array
    {
        if (!$this->vite->isRunningHot()) {
            return null;
        }

        $url = trim((string) @file_get_contents($this->vite->hotFile()));
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $websocket = $parts['scheme'] === 'https' ? 'wss' : 'ws';

        return [
            'http' => $parts['scheme'].'://'.$authority,
            'ws' => $websocket.'://'.$authority,
        ];
    }
}
