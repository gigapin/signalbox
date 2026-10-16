# Test infrastructure

M0.06 separates automated checks from application data and adds React DOM testing. The stack remains Pest 4/Laravel 13, PostgreSQL 16, Redis 7, Inertia 2, React 19, TypeScript and Vite 8. The official references used for configuration are [Laravel testing](https://laravel.com/docs/13.x/testing), [Vitest](https://vitest.dev/guide/), [React Testing Library setup](https://testing-library.com/docs/react-testing-library/setup/), and the [official PostgreSQL image](https://hub.docker.com/_/postgres). See [TASKS.md](TASKS.md) for remaining CI and product work.

## Complete verification

From the repository root, after the normal Docker bootstrap and dependency installation:

```sh
docker compose run --rm node npm ci --ignore-scripts --strict-peer-deps
./scripts/verify.sh
```

The executable POSIX shell script performs Compose validation, `composer verify` in `php-test`, and `npm run verify` in the Node container. It stops on the first failing command. Backend verification includes the PHP formatter dry-run, Larastan and all Pest suites; frontend verification includes lint, formatting, TypeScript, five quality-tool tests, React DOM tests and production compilation. It does not apply formatting fixes or run any Git command.

The existing PHP/Node images, Composer dependencies and configured local `.env` are prerequisites. Compose still validates the development `DB_PASSWORD` setting while reading its full configuration, even though no application database is started. On a fresh checkout follow [DOCKER.md](DOCKER.md) first; do not overwrite an existing `.env` or regenerate its key. After a new npm lock, refresh both the host installation (if used) and the separate Node Docker volume as appropriate.

The verification command starts only `postgres-test` and `redis-test` as backend dependencies. Web, Horizon, scheduler and Reverb need not be running. Existing application containers are not stopped, recreated or reset. Test services are left running so subsequent test runs can start quickly.

## Isolated services

| Service | Purpose | Storage / access |
|---|---|---|
| `php-test` | One-off PHP runtime with `RUN_DOCKER_TESTS=1` and testing environment. | Same non-root image/source mount as the app; depends only on test services. |
| `postgres-test` | PostgreSQL 16, database and role both `signalbox_test`. | Separate container, no host port, data directory on tmpfs. |
| `redis-test` | Real queue/cache/lock adapter tests. | Separate container, no host port, no RDB/AOF persistence, tmpfs data directory. |

These services use the `testing` profile. Explicit `docker compose run php-test` activates its declared dependencies. Database fixture credentials are public, disposable test data (`signalbox_test`); they do not replace application credentials or create a role in the application's PostgreSQL container. Both test service images reuse the already pinned PostgreSQL/Redis digests.

PostgreSQL's test data directory is ephemeral: stopping/removing its container discards its contents. Redis test data is in memory and persistence is disabled. Every new Pest process using the integration suite runs `RefreshDatabase` once before its first database fixture, recreates the **dedicated test schema**, then wraps each integration test in a transaction. Synthetic accounts/tokens are rolled back; sequence values can still advance. No migration command is run against the development database.

To release test-service memory without touching application volumes:

```sh
docker compose --profile testing stop postgres-test redis-test
```

A later test run starts and initializes them again. Do not use a project-wide `down --volumes` to reset tests: the application volumes are unrelated to this workflow.

## Pre-migration guard and configuration

`Tests\TestCase::createApplication()` rejects cached application configuration and prevents stray Laravel HTTP client requests. With integration opt-in, it calls `TestDatabaseGuard` before Laravel's migration traits execute. The guard checks:

- The runtime is `testing` and configuration is uncached.
- The driver is PostgreSQL, and both database/account have the dedicated test identity.
- PostgreSQL and Redis URLs are absent, so a URL cannot silently override individual fields.
- Hostnames select the explicit test services, or localhost/127.0.0.1 for dedicated CI services; application service names `postgres`/`redis` are rejected.
- Read-only PostgreSQL queries report the expected database and current role before any schema mutation.

`phpunit.xml` forces the database name, account, fixture password, PostgreSQL driver and port, empty connection URLs, Redis test prefix/databases and null broadcast driver. PHP process host settings can select the dedicated Docker services or CI loopback; `.env` is not the source of test credentials. Ordinary test cache/session/mail stay in memory, queue execution stays synchronous and log output is discarded. Fake Stripe/Reverb credentials and operator allowlists remain isolated as in M0.04.

The old M0.03/M0.04 command `docker compose exec -e RUN_DOCKER_TESTS=1 web ...` is now intentionally refused, before connecting to the application database. Use `php-test`. If a compiled `bootstrap/cache/config.php` exists, clear it explicitly through the normal local setup before running tests; the suite does not delete it automatically.

Twelve pure unit cases verify accepted configuration and rejection of cached state, non-testing environment, SQLite, wrong database/account, development service names, URL overrides and mismatched server identities. An HTTP feature test confirms an unfaked request is rejected before network access. This guard is for the automated test lifecycle, not a production database access policy.

## Pest suites and factory coverage

| Suite / path | Scope |
|---|---|
| `tests/Unit` | Pure guard rules without Laravel boot or infrastructure. |
| `tests/Feature` | HTTP/Inertia shell, liveness, authorization boundaries, service configuration, fake Reverb transport and HTTP test harness. |
| `tests/Integration` | Real PostgreSQL/Redis behavior with guarded setup and database rollback. |
| `tests/Support` | Test-only infrastructure guard; not application domain logic. |
| `tests/Fixtures` | Temporary search model for adapter verification; not a production searchable entity. |

`tests/Pest.php` attaches the Laravel test case to Feature/Integration. It attaches `RefreshDatabase` only when integration opt-in is enabled, so an ordinary offline run does not initialize a database trait merely because integration tests are discovered and skipped. The historical `docker` group name and `RUN_DOCKER_TESTS` flag are retained; the execution destination is now strictly isolated.

Integration tests cover PostgreSQL transactions, Redis cache/lock/queue transport, Sanctum hash/abilities/revocation/expiry and Scout queries with an explicit synthetic owner constraint. User factory tests verify distinct synthetic emails, verified/unverified states, hashed sample passwords, persistence and hiding authentication fields in serialization. No customer, team, monitor or billing factory is invented before its domain model exists. Factory passwords are non-production fixtures; no new demo accounts are seeded.

Commands:

```sh
# Offline feature/unit run; nine real-service cases are explicitly skipped.
composer test

# All suites, including migrations and all nine integration cases.
docker compose run --rm php-test composer test

# Just the integration group, with the same safety checks and database setup.
docker compose run --rm php-test php vendor/bin/pest --group=docker

# Backend formatter, static analysis and complete test suite together.
docker compose run --rm php-test composer verify
```

The PHP suite is currently sequential. Parallel database provisioning and race-condition tests must be added when concurrency-sensitive domain work requires them; a shared single test database is not presented as parallel-safe.

## React DOM tests

`vitest.config.ts` runs Vitest 5 with React's Vite plugin and jsdom. It deliberately does not load Laravel's asset plugin, open a development server or modify `public/hot`. Test inputs are only `tests/frontend/**/*.test.ts(x)`; the existing Node quality-tool suite is separate. Files run sequentially with one concurrent worker and per-file isolation. Assertions and lifecycle helpers are imported explicitly, avoiding production test globals.

`tests/frontend/setup.ts` registers jest-dom matchers, unmounts React trees after each case, restores simulated timers and resets page metadata. Unmocked `fetch` and `XMLHttpRequest.send` calls fail immediately. jsdom does not enable external resource loading. Tests must provide response doubles or intercept a transport boundary when testing a network interaction; the suite makes no real network call.

`renderInertia.tsx` mounts the actual installed `App` provider using a synthetic public page response typed from the adapter, without mocking `usePage`. Three initial tests verify:

1. The existing Home page exposes its supplied name, heading and named landmarks, while accurately showing unfinished application features.
2. Server-provided markup in the product name is escaped as text instead of creating an image element.
3. A test-only native Inertia `Link` receives keyboard focus and calls the router through Enter activation; only the router's network boundary is intercepted.

The link fixture is not a new product navigation feature. These are DOM-level component/adapter tests, not an end-to-end browser session or a complete accessibility audit. No arbitrary sleeps or live services are used. Add loading/error/empty-state and keyboard tests alongside each future interactive feature; the current product page has no form or monitoring controls to exercise.

```sh
npm test
npm run test:watch
npm run verify
```

`test` runs Vitest once and exits; `test:watch` is explicitly interactive. `verify` runs existing frontend quality checks, DOM tests and the production build. TypeScript and ESLint now include test source plus `vitest.config.ts`; formatting includes frontend tests. Reports/coverage outputs are ignored; no coverage target or coverage measurement is claimed.

## Compatibility and evidence limits

New test dependencies are exact-pinned in npm's lock. React 19, Inertia 2, Vite 8 and all existing direct dependency versions are preserved. jsdom 30.1.3 requires Node 22.22.2 on Node 22, so the minimum patch rises from 22.13.0 to **22.22.2**; the major stays 22, npm stays 10, and the existing host/pinned Docker image satisfy the floor. This is documented in [DEPENDENCIES.md](DEPENDENCIES.md).

No real browser, Stripe account, external network or CI execution is claimed here. GitHub Actions for PHP 8.4/8.5 remains M0.07, and the final full-project clean-checkout proof remains a milestone exit criterion. The dedicated database/service lifecycle and complete verification command have been exercised locally with actual PostgreSQL 16 and Redis 7.

## M0.07 CI follow-up

[CI.md](CI.md) documents the implemented workflow, event policy and every gate. CI explicitly enables integration tests and uses `--fail-on-skipped`; the same database guard accepts its dedicated loopback services. PHP 8.4 and frontend run on PRs and staging/main pushes; PHP 8.5 runs only on main pushes or manual dispatch on main by explicit user decision. Historical M0.06 evidence above does not claim remote workflow execution; current evidence and remaining acceptance checks are in [TASKS.md](TASKS.md).
