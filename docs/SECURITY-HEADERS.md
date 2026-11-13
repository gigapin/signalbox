# Browser security headers

Added on 2026-11-13 for the security review finding SEC.04 ([TASKS.md](TASKS.md)). Before this change no response set a Content Security Policy, HSTS, framing or MIME-sniffing protection, apart from `nosniff` on the PDF report, and the Caddy template added none. React and Blade already escape output and no HTML sink was found, so these headers are a second line of defence, not a fix for a known injection.

## Where the headers come from

`App\Http\Middleware\SetSecurityHeaders` is the first global middleware (`bootstrap/app.php`). Laravel renders exceptions inside the pipeline, so 404, 419, 429 and 500 responses, refusals from `GuardStatusPageHosts` and custom-domain pages all pass through it. Before handling the request it creates one nonce with `Vite::useCspNonce()` and hands it to `Horizon::cspNonce()`; afterwards it adds:

| Header | Value | Scope |
|---|---|---|
| `Content-Security-Policy` | built by `App\Services\Security\ContentSecurityPolicy` (below) | every response; `-Report-Only` while `APP_DEBUG` is true |
| `X-Content-Type-Options` | `nosniff` | every response |
| `X-Frame-Options` | `DENY` | every response, for browsers without `frame-ancestors` |
| `Permissions-Policy` | accelerometer, camera, geolocation, gyroscope, magnetometer, microphone, payment and usb disabled | every response |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | only when the route did not set its own; account, invitation and custom-domain responses keep `no-referrer` |
| `Strict-Transport-Security` | `max-age=31536000` | only on the primary origin (`APP_URL` host) over HTTPS |

HSTS has no `includeSubDomains` and no `preload`. Custom status domains belong to customers, so the application never commits their hostnames to HTTPS for a year. Covering other subdomains of the primary domain, or joining the preload list, is a deployment decision for M12.

A request with a malformed `Host` header is still answered 400 by the framework, as before; the headers are added anyway.

## Content Security Policy

```
default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self';
script-src 'self' 'nonce-…'; style-src 'self' 'nonce-…'; img-src 'self' data:; font-src 'self';
connect-src 'self' ws(s)://<browser Reverb host>:<port>
```

- **Scripts and styles** come from the origin or carry the nonce. Laravel adds it to `@vite` and `@viteReactRefresh` tags. The published error layout `resources/views/errors/minimal.blade.php` is a copy of the framework's, changed only to add the nonce to its two `<style>` blocks; keep it in step on framework upgrades. Horizon receives it through `Horizon::cspNonce()`.
- **No `'unsafe-inline'`** in production. Inertia's progress bar normally injects a `<style>` element without a nonce, so `resources/js/app.tsx` sets `progress.includeCSS` to false and `resources/css/app.css` holds the same rules with Inertia's default color. The status page snapshot uses the `whitespace-pre-line` class instead of an inline `style` attribute. Inertia's development error dialog still injects an unstyled `<style>`; only its backdrop styling is lost.
- **`connect-src`** adds the browser Reverb endpoint from `broadcasting.browser.*` only when `BROADCAST_CONNECTION=reverb`, with the page host when `REVERB_BROWSER_HOST` is blank, the same rule as `HandleInertiaRequests::realtime()`; `https` becomes `wss`.
- **`form-action 'self'`** blocks forms posting to other origins. A future flow that posts to another origin, such as a payment provider, must widen it deliberately.
- **Horizon** (`/horizon` and its API, operator-only) also gets `'unsafe-eval'`, because its Vue bundle contains a runtime template compiler using `new Function`, and `https://fonts.bunny.net` for its fonts. These sources never reach other pages. Whether Horizon actually needs `'unsafe-eval'` was not checked in a browser (no operator credentials were available); remove it if a browser check shows it is unused.
- **Vite development server**: while `public/hot` exists (local `npm run dev` only), its origin and WebSocket are added and `style-src` gets `'unsafe-inline'` for the styles Vite injects. Production builds never create the hot file.

### Report-only while debugging

With `APP_DEBUG=true` the policy is sent as `Content-Security-Policy-Report-Only`: the framework debug page relies on inline scripts and styles, and developers still see every violation in the console. `EnsureSafeProductionSettings` refuses debug outside `local` and `testing` ([DOCKER.md](DOCKER.md#production-settings-enforced-at-startup-2026-11-13)), so every deployment enforces the policy.

## Static files

Files under `public/` that the PHP server serves directly, such as `public/build` assets, do not pass through Laravel and receive none of these headers. Only the HTML document needs the policy; for the assets, the production web server (M12.01/M12.02) should add at least `X-Content-Type-Options: nosniff`. The Caddy template currently proxies every request to the application and adds no header itself.

## Verification

Automated tests are described in [TESTING.md](TESTING.md#browser-security-headers--sec04). On 2026-11-13 a temporary headless Brave 157.1.99.57 run (`playwright-core` 1.49.1 installed only in a scratch directory) checked the local development stack with real PostgreSQL, Redis, Reverb and the production build:

- enforced policy (a temporary second web process with `APP_DEBUG=false` on port 8001, stopped afterwards): login, public status page, a 404 page and the Synthetic Alpha dashboard produced no violation; the Reverb WebSocket opened and exchanged frames; an Inertia navigation showed the progress bar with its static styles; a nonce-less inline script and style injected as a negative control were blocked;
- report-only policy (the normal development server, `APP_DEBUG=true`): the same pages produced no report, and the negative control was reported but executed.

Not checked in a browser: Horizon, custom domains over real TLS, Firefox and Safari.
