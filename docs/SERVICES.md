# Application service configuration

M0.04 configures the already locked Horizon, Reverb, Sanctum, Scout and Cashier packages without changing dependencies. [DOCKER.md](DOCKER.md) documents the local runtime; [TASKS.md](TASKS.md) records verification and remaining milestones. The configuration is adapted from the installed package templates and the official [Horizon](https://laravel.com/docs/13.x/horizon), [Reverb](https://laravel.com/docs/13.x/reverb), [Sanctum](https://laravel.com/docs/13.x/sanctum), [Scout](https://laravel.com/docs/13.x/scout), and [Cashier](https://laravel.com/docs/13.x/billing) documentation.

## Updating an existing Docker environment

Do not replace an existing `.env` or regenerate its application key. Add the new settings from `.env.example` as needed; empty Stripe credentials are supported and no Stripe account is required to boot or test the application. No real Stripe call is made during setup or ordinary tests.

```sh
docker compose config --quiet
docker compose run --rm web php artisan config:clear
docker compose run --rm web php artisan migrate
docker compose up -d web queue scheduler
```

The new migration creates only `personal_access_tokens`. It does not create billing tables or modify existing users. The `queue` service now runs `php artisan horizon` instead of one generic queue worker. Restart it after changing PHP source or Horizon configuration:

```sh
docker compose restart queue scheduler
docker compose exec web php artisan horizon:status
docker compose exec web php artisan horizon:supervisors
docker compose exec web php artisan horizon:snapshot
docker compose exec web php artisan schedule:list
```

Snapshots run every five minutes, with overlap locks expiring after five minutes; the scheduler process must be running. The snapshot command records queue observability only. Monitor dispatch remains M3.

## Horizon ownership, retries and access

`config/horizon.php` defines one worker for each queue: `default`, `checks:eu` and `checks:us`. Separate supervisors prevent a busy region from starving another. Regions are simulated, not geographically deployed. Every baseline worker has one attempt, a 30-second timeout and a 128 MB memory limit. Horizon's master limit is 64 MB. Redis's 90-second reservation lifetime exceeds the worker timeout. The Compose queue shutdown grace is 60 seconds; fast termination is disabled. Future notification jobs can explicitly define their delivery retries in M5; monitoring check failures must remain recorded domain data.

Horizon metadata uses the default Redis connection and its own `HORIZON_PREFIX`, not the reserved internal connection name `horizon`. Successful metadata is retained for 60 minutes and failed metadata for seven days; those limits concern queue diagnostics, not check-result retention.

The dashboard at `/horizon` and its JSON API use the same authorization callback. Access requires an authenticated user with a verified email that exactly matches an entry in comma-separated `HORIZON_ADMIN_EMAILS`. An empty allowlist denies everyone, including local development. There is no local-environment bypass. Team owner/admin roles do not grant access to this global operator dashboard.

Until M1 provides login and verified identities, a `403` in the browser is expected; process status is available through the console commands above. No operator account, password or login bypass is seeded. Access tests use synthetic, unpersisted identities. M11 can extend operator authorization and observability without weakening this boundary.

## Reverb listener and publishing

`config/reverb.php` configures one local listener with a 100-connection limit, 10,000-byte request/message limits, 60-second pings and a 30-second activity timeout. Client event messages are disabled. Message rate limiting allows 60 attempts per 60 seconds and terminates connections exceeding that limit. Multi-server scaling is disabled in this local baseline.

`REVERB_ALLOWED_ORIGINS` is a comma-separated list of browser **hostnames**, with no schemes or ports. The default is `localhost,127.0.0.1`. No global wildcard is configured. Reverb compares the hostname from the Origin header; this is not a substitute for private-channel authorization. Keep the listener bound to loopback through Compose. TLS and production hostnames belong to deployment configuration.

Configure non-empty local `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` before starting the optional profile. The ID and public key identify the application; the secret signs backend publishing and must never be exposed in browser configuration or committed. Generate random values locally, for example with `openssl rand -hex 32`, and put them in `.env` only.

```sh
docker compose --profile realtime up -d reverb
docker compose restart reverb
docker compose logs --tail=50 reverb
```

There are three different network coordinates:

| Coordinate | Purpose |
|---|---|
| `REVERB_SERVER_HOST=0.0.0.0`, internal port `8080` | Bind the listener inside its container. |
| Compose `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http` | Publish signed backend events over the internal Docker network. |
| Browser `127.0.0.1:${REVERB_FORWARD_PORT}` | Connect from the developer's browser through the loopback port mapping. The current local `.env` uses port `18080` because `8080` was occupied. |

Compose's publishing coordinates override the host-oriented values in `.env`; no secret or browser asset receives Docker DNS names. Outside Docker, `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` must point to an actually reachable listener. `config/broadcasting.php` sets HTTP connection/response timeouts of three/five seconds and provides `reverb`, `log` and `null` connections.

`BROADCAST_CONNECTION=log` remains the offline-friendly default. Set it to `reverb` explicitly once the listener and credentials are configured, then clear cached configuration and restart long-lived queue processes. No actual monitor events or browser Echo client exist yet; those are M6. `/broadcasting/auth` stays unregistered until team policies and channel authorization are implemented. The test publishing probes use synthetic public channel names only and do not publish tenant data.

## Sanctum and search boundaries

`User` now has `HasApiTokens`; `personal_access_tokens` stores hashed credentials, polymorphic ownership, abilities and expiry metadata. `config/sanctum.php` applies a default token lifetime of 1,440 minutes (24 hours); an earlier explicit per-token expiry takes precedence. Token strings use the fixed `signalbox_` prefix. Keep `SANCTUM_EXPIRATION` positive if overriding the default: the package interprets zero as no global expiry. This lifetime is an initial operational default, not a subscription-plan limit.

Middleware aliases `abilities` and `ability` are registered for the future API. No token issuing endpoint, API resource, wildcard token policy or team authorization is introduced. Inertia browser requests continue to use Laravel's web sessions; no separate SPA or stateful API middleware is enabled. Token ownership alone does not provide tenant isolation: M1/M10 must combine explicit abilities with team policies. Token issuance and pruning schedules belong to the API lifecycle in M10.

`config/scout.php` selects the database driver directly, with no external search credentials and no background indexing. The obsolete bootstrap `SCOUT_DRIVER` environment setting is no longer needed. Chunk sizes are 500 records, soft-deleted indexing and search analytics are disabled, and synchronization is configured after commit. No production model is searchable yet. M2 must explicitly select publishable/searchable fields and apply tenant constraints; Scout is not itself a tenancy boundary.

## Stripe test-only preparation

`config/cashier.php` reads `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `CASHIER_CURRENCY` and `CASHIER_CURRENCY_LOCALE`. The default currency/locale are EUR/English. Payment notifications and remote invoice assets are disabled; no Stripe payload logger is enabled. A webhook signing secret identifies an endpoint, not its test/live mode, so M9 must pair it with a test-mode Stripe endpoint.

`EnsureStripeTestCredentials`, invoked during application boot, permits empty credentials and rejects any supplied public/server key without `pk_test_` / `sk_test_`, respectively. It checks the mode prefix, not whether Stripe recognizes a key, and never includes credential values in its error messages. Live-mode credentials stop boot in every environment, including production: the specified product demo uses Stripe test mode. Changing that boundary requires an explicit documented scope decision.

Cashier's automatic payment and webhook routes are disabled during service registration. In particular, `/stripe/webhook` and `/stripe/payment/{id}` return 404. No CSRF exception or unsigned webhook is introduced. Package migration publication is deliberately deferred: upstream defaults would attach billing fields to `users`, whereas Signalbox subscriptions belong to `Team`, which is introduced in M1. M9 must configure the team customer model, adapt migrations, authorize checkout/portal access and implement signed idempotent webhooks. This is preparation, not an implemented billing flow.

## Automated checks

Ordinary tests have fake package credentials and empty operator allowlists forced in `phpunit.xml`, with in-memory cache/session and synchronous queues. They never open network connections. Reverb tests exercise the actual protocol implementation over a fake socket transport, including accepted/rejected origins and disabled browser publishing. A fake Guzzle transport verifies the backend publishing endpoint, body hash and HMAC signature. Stripe mode tests do not contact Stripe.

```sh
docker compose exec web composer test
docker compose run --rm php-test php vendor/bin/pest
docker compose exec web composer format:check -- --sequential
docker compose exec web composer analyse -- --debug
```

The `docker` group adds real PostgreSQL/Redis tests. Sanctum tests use synthetic accounts inside rolled-back transactions and test hashed token lookup, bearer abilities, revocation, explicit expiry and the exact 24-hour boundary via simulated time. Scout uses a temporary PostgreSQL relation, verifies constrained search results and the empty case, then rolls it back. The tests now refresh only the dedicated test schema and never reset development application tables; synthetic account/token fixtures are rolled back. PostgreSQL sequence values may advance despite rollback. M0.06 now supplies a dedicated PostgreSQL/Redis test lifecycle and pre-migration guards; see [TESTING.md](TESTING.md). Integration tests no longer run against development services.

Manual local smoke verification additionally checks that Horizon is running with all three supervisors, snapshots can be stored, backend Reverb publishing succeeds with the real signature and is rejected with HTTP 401 using an invalid secret. A full browser real-time workflow, team billing, real Stripe test account integration and CI remain outside this milestone.
