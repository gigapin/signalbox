# Custom status page domains

M8.05 adds one optional hostname per status page, DNS ownership verification, exclusive expiring associations, restricted HTTP routing and the TLS admission contract for deployment. The primary `/status/{slug}` address keeps working. Scope and verification evidence: [TASKS.md](TASKS.md).

## Claim and ownership

Owners/admins manage a claim independently of page contents. The hostname is normalized using the existing network-free IDNA parser: lowercase ASCII, no trailing dot, IP, URL, port, path, wildcard or local/special suffix. The maximum is 242 bytes, leaving room for `_signalbox.` within a DNS name. The configured application hostname and its subdomains are reserved. Host normalization alone grants no authority.

`status_pages` stores `custom_domain`, a random UUID `domain_challenge` and UTC `domain_checked_at`. The proof is a TXT record:

```text
Name:  _signalbox.status.customer.com
Value: signalbox-verification=<the page's current UUID>
```

The manager screen shows the exact values. Keep this record after verification. Saving the same normalized name preserves its generation and lease; replacing/removing it immediately deletes this page's binding and rotates/removes its proof. Pending claims may share a hostname, so a claim without proof cannot reserve somebody else's domain.

`status_domain_bindings` is a global name registry with a hostname primary key and one binding per page. Its composite foreign key binds hostname, tenant, page and generation to the exact pending claim. This extra table separates shared-name allocation from tenant-owned settings: it prevents pending-claim squatting without weakening exclusive active routing.

`ManageStatusDomains::verify` snapshots an authorized claim under Team → Page locks, releases its transaction, checks DNS, then locks again and rechecks both current permissions and the exact generation. DNS for an old generation or a revoked manager cannot activate a claim. A conditional PostgreSQL upsert permits only the same owner/generation or an expired registry row; two tenants with valid TXT proofs cannot both activate the same name. A second owner must wait for removal or expiry of the current lease. Removal of a losing pending claim cannot revoke the winner's binding.

Verification uses the existing `DnsLookup`, `DnsTransport` and private event loop with the operator's system resolver. The cumulative deadline is 2000 ms, including bounded aliases and UDP-to-TCP continuation, with no automatic retry, search-domain expansion or hosts-file lookup. TXT segments are concatenated exactly and only the requested owner/validated alias chain is accepted. No HTTP/TLS connection is made to the submitted domain, no user-selected resolver is supported and no A/AAAA address is contacted. The recursive resolver is trusted; this task adds no DNSSEC validator. See [OUTBOUND-SECURITY.md](OUTBOUND-SECURITY.md).

## Expiry and renewal

A positive proof grants a 24-hour lease. The exclusive expiry is checked in the live routing query and TLS admission query, so scheduler failure cannot make a binding permanent. A negative, malformed or timed-out DNS answer immediately removes this page's binding. An in-flight request admitted before revocation may finish; the next request is denied. A cached recursive DNS proof can remain visible until its DNS TTL expires.

`status-pages:verify-domains` runs every five minutes with Redis single-server/overlap coordination. Each pass selects at most 25 claims whose last attempted check is at least an hour old, unattempted first. It scans only identities globally and reenters the explicit owning tenant for each phase. Failed claims stay pending and are reconsidered later. Infrastructure errors are isolated, logged with team/page IDs only and produce a nonzero command result; ordinary missing proof is a valid negative outcome. Manual verification is limited to three attempts a minute per actor/team/page. Commands accept `--limit=1..100`.

The 24-hour lease bounds stale authorization, rather than guaranteeing renewal for an unmeasured number of claims. A slow resolver or a growing backlog can delay renewal; monitor due/expired claims and increase capacity deliberately after measuring it. No throughput benchmark is claimed.

## HTTP origin boundary

`GuardStatusPageHosts` runs globally before sessions. It validates raw `Host`, never forwarded host headers. The primary hostname comes from `APP_URL`; localhost, 127.0.0.1 and IPv6 loopback are additional origins only in local/testing. Unknown, unverified, expired, malformed or draft custom hosts return 404 before starting a session. There is no cached hostname authorization.

An admitted custom origin exposes only:

| Method/path | Behavior |
|---|---|
| GET/HEAD `/` | Same-origin redirect to its own `/status/{slug}` address. |
| GET/HEAD `/status/{its-slug}` | Existing public page/password form, with the same content cache. |
| POST `/status/{its-slug}/unlock` | Existing password check and session rotation. |
| POST `/status/{its-slug}/subscribe` | Existing double opt-in subscription and live password check. |

Every other path/verb is 404, including other pages, account/login, team routes, heartbeat/API/broadcast entrypoints and email capabilities. The public controller also checks the admitted page identity, preventing a concurrent slug reuse from selecting a different page. Email capability/page links remain on configured `APP_URL`, so domain expiry does not strand unsubscribe links. Slug changes alter the allowed path immediately; `/` points to the current path.

`StatusDomainSessions` temporarily installs a separate session manager/store and cookie named from the hostname, page and proof generation. Its host-only cookie is HttpOnly, SameSite=Lax and Secure on HTTPS. Primary session data, password unlocks and account identity are not inherited. Web CSRF protection and encrypted cookies remain active; primary-origin CSRF tokens cannot authorize custom-origin forms. Authentication resolution is masked for the public request, and the Inertia projection independently excludes identity and realtime metadata. Original bindings/configuration are restored in `finally`, including errors, for reused containers. Responses are private/no-store and use `Referrer-Policy: no-referrer`.

Production custom origins require HTTPS. `TRUSTED_PROXY_IPS` supplies explicit proxy peer IPs/CIDRs for scheme/client metadata only. `X-Forwarded-Host` is excluded even for trusted peers. Keep the primary session cookie host-only too (`SESSION_DOMAIN` unset) and proxy Host unchanged; broad shared cookie domains defeat browser-side separation before an application sees a request.

## TLS deployment contract

The deployment terminates TLS with Caddy and ACME. The [Caddyfile template](../docker/caddy/Caddyfile.example) defines an explicit primary site, an on-demand custom-domain site and `strict_sni_host on`, preventing an HTTP Host from differing from TLS SNI. The application implements the private `GET /internal/status-domains/allow?domain=...` admission endpoint. It returns 204 only for a live, verified, public binding; every other name returns 404. Admission is an indexed database lookup with no DNS/provider call, session or account response. Missing/unavailable storage cannot authorize issuance. The template passed Caddy 2.11.7 adaptation/validation in a disposable container with networking disabled; the exact image digest is in [DEPENDENCIES.md](DEPENDENCIES.md).

The endpoint checks both raw `REMOTE_ADDR` against `STATUS_DOMAIN_TLS_PEERS` (default loopback) and exactly one `SignalboxTlsSecret` header against `STATUS_DOMAIN_TLS_SECRET`, before routing and again at the actual endpoint. The configured secret must be 32 random bytes encoded as 64 lowercase hex characters; absent, malformed, wrong or duplicated credentials return 403 before the ownership lookup. Forwarded client identities confer no authority. This second check also covers front-controller `/index.php/...` and decoded path variants, for which a path-only ingress filter is insufficient.

The template directs `ask` to a separate `http://127.0.0.1:9123` listener bound explicitly to loopback. That listener forwards only GET/HEAD on the exact admission path to the private backend and sets the secret header using Caddy's runtime environment placeholder. Both public virtual hosts remove any client-supplied `SignalboxTlsSecret` and block `/internal/status-domains/*` plus `/index.php*`. The separator-free header name avoids PHP's dot/underscore/hyphen field-name collisions. The secret is never placed in a URL, query, redirect or response. Caddy's configured request mechanism is described under [on-demand TLS](https://caddyserver.com/docs/caddyfile/options#on-demand-tls); the private adapter uses the documented [bind](https://caddyserver.com/docs/caddyfile/directives/bind) and [upstream header](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy#headers) directives.

Share the same secret with Laravel and this Caddy listener, protect their environment/configuration and never expose port 9123 or the PHP backend to public clients. Other local processes inside this trust boundary can access the private listener. For separate containers, adapt private bind/network/backend addresses deliberately and permit only the actual raw ask proxy peers; do not turn the dedicated listener into a public proxy. Secret absence denies only certificate admission, without blocking the rest of the application. Existing loaded certificates are unaffected until Caddy needs a new admission decision.

The response contract, need for an issuance allowlist and avoiding network calls inside admission follow [Caddy's on-demand TLS documentation](https://caddyserver.com/docs/caddyfile/options#on-demand-tls). The SNI/Host check follows [Caddy's server options](https://caddyserver.com/docs/caddyfile/options#strict-sni-host). Proxy/host trust follows [Laravel's request documentation](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies).

Before deployment:

1. Set the primary HTTPS `APP_URL` and matching Caddy `APP_HOST`; publish the primary application DNS and supply an operational ACME email.
2. Point a customer hostname's CNAME to the public application ingress hostname. For an apex name, use the ingress's published A/AAAA addresses or an appropriate DNS-provider flattening feature. TXT verification proves ownership, not traffic routing or certificate readiness.
3. Apply migrations, rebuild assets and load scheduler code. Set `TRUSTED_PROXY_IPS` to the actual proxy peers and restrict/firewall the backend. Configure `STATUS_DOMAIN_TLS_PEERS` for the internal ask proxy. Generate a secret with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and install the same `STATUS_DOMAIN_TLS_SECRET` securely in Laravel and Caddy; never commit its value. Deploy the PHP authentication and Caddy listener together, refresh cached configuration and reload long-lived processes. For rotation, update both sides together; mismatched keys deny issuance until they agree.
4. Keep ACME challenge ports reachable, persist/protect Caddy certificate/account storage, validate the template with the pinned deployment version and test first against ACME staging. Test renewal, expiry, removal, password/CSRF forms, DNS changes and SNI/Host mismatches before enabling public traffic.

Certificate issuance and renewal belong to Caddy. An already loaded certificate is not removed merely because a binding expires; HTTP routing still checks the live association on every request. The example does not start a service, issue a certificate, alter Compose or claim a production deployment. Production image pinning, persistent certificate storage, staging/live issuance and operational deployment remain M12.

## Local adoption and verification

Apply `2026_11_02_000024_create_status_domain_bindings.php` deliberately to development, rebuild assets and reload scheduler/long-lived application code. `.env.example` documents the peer settings and the secret required only when enabling certificate admission; no Composer/npm dependency or lockfile changes. The local Compose application still runs over HTTP and issues no TLS certificate.

```sh
docker compose exec web php artisan migrate
docker compose run --rm node npm run build
docker compose up -d --no-deps --force-recreate queue scheduler
docker compose run --rm php-test vendor/bin/pest tests/Integration/StatusDomainsTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/StatusDomains.test.tsx
```

`StatusDomainsTest` uses synthetic DNS, PostgreSQL and isolated Redis sessions. Its child-process race checks that DNS runs outside a transaction and only one of two valid tenant proofs wins. The complete role/membership matrix includes all three managing routes and raw snapshots of page/registry state. Schema rollback tests include the new dependent registry. Backend HTML tests must also pass without `public/build`, matching CI.

## TLS admission proxy regression (SEC.07)

`StatusDomainsTest` covers absent/malformed/incorrect/duplicate secrets from allowed peers, refusal before ownership queries, valid stateless admission, untrusted raw peers despite forwarded headers, and actual kernel routing of prefixed/encoded paths. The endpoint keeps returning 204 for verified public bindings and 404 for unknown/malformed domains only after authentication.

The additional `RunTlsAdmissionProxyProbe.php` exercises the real Caddy binary and PHP built-in server on three temporary loopback ports using the isolated test database. It validates the unchanged production template, then derives an HTTP-only runtime with ACME and the admin API disabled. Its 18 checks cover direct PHP authentication, public path blocking on both virtual hosts, removal of spoofed credentials on a test sentinel path, and authentication through the private ask listener. An unclaimed synthetic domain is used, so authenticated requests return 404 without creating fixture records. Child processes and temporary Caddy state are cleaned up in `finally`.

After running the normal isolated database suite, extract the already pinned Caddy binary and execute the probe:

```sh
docker run --rm --network none --entrypoint cat caddy:2.11.7-alpine@sha256:e70b164da81ef698b3ad19af799ea6086ce6d9e48ed4079eb9fe26f6fbf14ee5 /usr/bin/caddy > /tmp/signalbox-caddy
chmod +x /tmp/signalbox-caddy
docker compose run --rm -v /tmp/signalbox-caddy:/tmp/caddy:ro php-test php tests/Fixtures/RunTlsAdmissionProxyProbe.php
```

No actual certificate is issued, no production secret is generated, and no development service or `.env` file is changed. Public TLS, ACME staging/renewal and deployment networking remain M12 acceptance work.
