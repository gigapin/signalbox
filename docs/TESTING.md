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

On CI, backend jobs create an empty `.env.testing` before installing PHP dependencies. This file supplies no configuration values; PHPUnit and the job environment remain authoritative. It avoids missing `.env` warnings on runners without a developer environment file. Local verification does not create or overwrite either environment file.

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

Integration tests cover PostgreSQL transactions, Redis cache/lock/queue transport, Sanctum hash/abilities/revocation/expiry and Scout queries with an explicit synthetic owner constraint. User factory tests verify distinct synthetic emails, verified/unverified states, hashed sample passwords, persistence and hiding authentication fields in serialization. M1.01 adds coherent Team/Membership factories and an explicit, environment-guarded synthetic seeder; see [IDENTITY.md](IDENTITY.md). Factory passwords are non-production fixtures. Ordinary test startup does not seed demo accounts.

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

The PHP suite is currently sequential. M1.01 adds a two-session PostgreSQL owner-lock contention test with a bounded database lock timeout and explicit fixture cleanup. This does not make the shared test database parallel-safe; parallel database provisioning remains future work.

`ContainerWebEnvironmentTest` starts the Dockerfile's actual development-server command on a temporary loopback port using only the guarded test database. Its initial GET requests the `Auth/Login` Inertia JSON response with a matching asset version, so it works both with and without compiled Vite assets. Cookie sessions persist CSRF state for the subsequent POST, which must return validation error `errors.email` for a nonexistent synthetic account. A failed probe includes the isolated server's console output, never response bodies or cookies; the child process is always stopped. To reproduce fresh-runner behavior, use a temporary source copy without `.env`, `public/hot` or `public/build`, prepare only an empty `.env.testing`, and mount that copy into `php-test`; do not rename or remove the development project's environment or assets.

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

## M1.01 domain verification

[IDENTITY.md](IDENTITY.md) documents the identity integration scenarios: creation and full rollback, real constraints, stale selection and membership revocation, owner roles, trial timestamps, factory states, guarded/repeatable seeding, incremental migration reversal and two-session owner-lock contention. Tests use the existing opt-in database guard and do not migrate or seed the development database. The contention probe connection copies only the already guarded test connection settings.

## M1.02 authentication verification

[AUTHENTICATION.md](AUTHENTICATION.md) documents the exact HTTP/security surface. Public-page and guest-boundary tests remain infrastructure-free. New PostgreSQL suites exercise registration with post-commit notification fakes, case-insensitive constraints, atomic rollback, login/logout/session revocation, email verification, reset-token lifetime/consumption, mandatory verification, confirmed/pending authenticators, challenge fingerprints/expiry, secret omission and recovery-code replacement. TOTP uses the application clock through a maintained-engine adapter; numeric cache counters are normalized before replay comparison.

Two-session probes verify contention for case-variant registration, reset-token consumption and recovery-code mutation. They use only connections copied from the guarded test database, bounded 100 ms PostgreSQL lock timeouts and explicit committed-fixture cleanup. They combine real lock contention with successful/replayed HTTP cases; they do not provision independent databases or make the complete suite safe for parallel execution.

DOM tests mount the real Inertia forms/provider and intercept only routing/fetch boundaries. They cover submit/loading/error behavior and credential clearing, registration fields, keyboard switching between TOTP/recovery methods, verification resend, missing-team state, sensitive fetches only on demand, recovery-code hiding/remount and HTTP 423 reauthentication handling. No real email provider or full-browser end-to-end run is claimed.

## HTTP child environment regression

`ContainerWebEnvironmentTest` starts the actual Dockerfile server argument vector on an ephemeral loopback port, supplies only guarded test database coordinates, and performs a real CSRF-protected login lookup for a nonexistent `.invalid` account. HTTP 422 confirms the child reached PostgreSQL and rejected credentials normally; the old command returned HTTP 500 after losing its container environment overrides. The test uses only test storage, keeps cookies in memory and stops the child in `finally`. It requires integration opt-in and does not create development accounts.

## M1.03 membership coverage

[TEAMS.md](TEAMS.md) documents the TeamMembership integration suite, fake-mail fixture and Teams DOM cases, including two-session PostgreSQL contention. The existing identity migration reversal test now also removes/reapplies invitation storage before its referenced team tables. This preserves its original identity-retention assertion as the schema grows. No test runs against application data or a real notification provider.

## M1.04 tenant boundaries

[TENANCY.md](TENANCY.md) describes the new unit and PostgreSQL integration tests. Tests cover fail-closed context, nesting and cleanup, tenant reads/writes/reloads, HTTP binding before private queries and policy roles. Redis serializes scalar fixture jobs consumed by one real worker across failure and a subsequent tenant; a test-only Artisan command verifies bounded CLI work. No application command or product job is added. Existing M1.03 concurrency and invitation regressions remain enabled; direct invitation assertions now establish explicit context.

## M1.05 authorization matrix

[AUTHORIZATION-MATRIX.md](AUTHORIZATION-MATRIX.md) documents TeamAuthorizationMatrixTest, its route catalogue and the two synthetic-aggregate fixture. Every private route is crossed with actor role and current/foreign/revoked access; separate tests check guest/verification gates, the complete actor/target hierarchy, requested roles, nested foreign IDs, account projections, switching and invitation capabilities. Rejected writes compare raw guarded-test database snapshots and fake notifications; successful operations assert actual domain effects and unchanged foreign data. Route discovery fails when domain routes are not classified, making extension an explicit requirement of later milestones. Existing concurrency, scope and real-worker suites remain required.

## M2.01 monitor foundation

[MONITORS.md](MONITORS.md) describes MonitorConfigurationTest and MonitorModelTest: immutable variant structure, exact typed round trips, encrypted storage/hidden fields, canonical regions, irrelevant-field cleanup, SQL constraints, UTC/DST, cascade/rollback and tenant isolation on the real model. Monitor factories require explicit tenant context and never provision a team. The identity rollback test includes the dependent monitors migration before removing teams, then reapplies it after its prerequisites; no baseline user data is dropped. Existing M1 authorization and worker/concurrency coverage remains enabled.

## M2.02 monitor input

[MONITOR-INPUT.md](MONITOR-INPUT.md) documents the reusable validator and HTTP adapter contract. MonitorInputSyntaxTest runs local parsers and byte/count boundaries without infrastructure; MonitorInputValidationTest uses the Laravel validator without database access. MonitorInputRequestTest opts into isolated PostgreSQL and exercises real Form Requests, global string middleware, tenant binding, explicit test-only Gate grants, denied access before decryption, exact sensitive bytes, partial updates and safe JSON/web errors. It registers test-only routes and asserts no monitor persistence. The explicit-patch test revalidates against a changed snapshot; database locking and mutation concurrency remain M2.04. No network requests or development database operations are needed.

Run `docker compose run --rm php-test composer test -- --filter=MonitorInput --fail-on-skipped --fail-on-warning` for this boundary and `./scripts/verify.sh` for the full regression suite, including existing authentication/authorization tests affected by global middleware configuration.

[OUTBOUND-SECURITY.md](OUTBOUND-SECURITY.md) documents the SSRF and redaction suites. OutboundAddressPolicyTest, OutboundDestinationGuardTest and SecretRedactorTest are infrastructure-free unit tests using `FakeHostResolver`; no real DNS is queried. GuardedHttpPinningTest starts a short-lived PHP built-in server on a free loopback port with a bounded readiness wait, allows only that synthetic origin past the suite-wide stray request guard, and proves address pinning and environment proxy bypass; it is skipped if no loopback port can be opened. Save-time rejection cases extend MonitorInputValidationTest and MonitorInputRequestTest.
