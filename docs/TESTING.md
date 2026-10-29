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

[MONITOR-CRUD.md](MONITOR-CRUD.md) documents the M2.04 suites. PlanLimitsTest is an infrastructure-free unit test of the plan matrix. MonitorCrudTest runs on isolated PostgreSQL with frozen time: effective plan boundaries, all mutations through routes and direct action calls, plan limits, reset rules and pause/resume semantics. Its contention case commits setup data through a second connection, holds the Team lock in an uncommitted transaction and expects the competing creation to fail with `55P03` under a 100 ms `lock_timeout`, as in TeamMembershipTest; no sleeps are used. MonitorRequestFixture now overrides the production store/update handlers to observe Form Request results without persistence; authorization in those tests comes from the real MonitorPolicy.

## M2.05 monitor pages

[MONITOR-UI.md](MONITOR-UI.md) lists the suites. MonitorPagesTest runs on isolated PostgreSQL with frozen time and covers search, literal escaping, tenant isolation, filters, pagination, rejected filters, secret-free props, quota hints and permissions. MonitorListingTest is in the Feature suite because Eloquent models need the application container, although it uses no database. TeamAuthorizationMatrixTest includes the four GET routes. tests/frontend/Monitors.test.tsx adds eleven DOM tests through the real Inertia provider, intercepting only `router` calls and animation frames.

## M2.06 integrated monitor coverage

[MONITOR-UI.md](MONITOR-UI.md#integrated-coverage-m206) maps every M2.06 criterion to its suite.
- **Lifecycle.** MonitorLifecycleTest drives all five types through the production pages and routes with Inertia-style JSON submissions. It covers wrong creation and edit input, viewer denial per type and search isolation on shared TLS hosts and DNS names.
- **Form contract.** `tests/Fixtures/monitor-form-payloads.json` is shared with `tests/frontend/MonitorPayloadContract.test.ts`: Vitest pins `buildPayload()` to it, and Pest posts the same payloads, so drift between the React form and server validation fails one of the two suites.
- **Concurrency.** MonitorQuotaConcurrencyTest starts the Dockerfile's real `artisan serve` command through `Tests\Support\IsolatedHttpServer`, with several built-in server workers, on the guarded test database only (array cache and mail, sync queue, cookie sessions). It reuses the same environment as ContainerWebEnvironmentTest, which now goes through that helper too. `stop()` also terminates the `php -S` master and its workers, which `artisan serve` would otherwise orphan. Page suites call `withoutVite()`, because the backend CI job runs without a frontend build.
- **Committed fixtures.** Data comes from `Tests\Support\CommittedTeamFixture`, which writes through a second connection because the server process cannot see the RefreshDatabase transaction. Its `cleanup()` runs in `finally`.
- **Lock probe.** The deterministic case keeps the fifth creation's Team lock in an uncommitted transaction. It ticks the pending Guzzle request while polling `pg_stat_activity` (after `pg_stat_clear_snapshot()`, because statistics views are snapshotted per transaction) until a backend waits on a lock. The poll has a 5-second limit. The case then commits and reads the quota error from the next Inertia page.
- **Burst.** The burst case sends four parallel submissions over one curl multi handle. It checks exactly one creation and three refusals. Its outcome does not depend on scheduling, so it cannot be flaky, but it is only a safety net: the lock probe is what proves serialization.

## MA platform administration

[PLATFORM-ADMIN.md](PLATFORM-ADMIN.md#tests) lists the suites. PlatformAdminAccessTest switches `app['env']` to `production` to check the second-factor rule, then restores it. Its Horizon checks replaced the database-free allowlist test, because the gate now reads the operator flag from PostgreSQL. In PlanOverrideTest, the lock contention case commits its team and operator through `CommittedTeamFixture` and holds the Team row lock in a savepoint; rolling back to the savepoint releases the lock before cleanup. TeamIdentityTest's migration reversal now includes `team_plan_overrides`, which depends on `teams`.


## Recoverable scheduling (M3.01–M3.02)

[MONITOR-SCHEDULING.md](MONITOR-SCHEDULING.md) describes the slot, configuration, outbox, fencing and recovery suites. The concurrency suite starts two bounded PHP subprocesses against the guarded test PostgreSQL database and observes their actual lock waits before releasing a barrier. PostgreSQL statistics snapshots are cleared on each poll so the observer sees new waiters. Committed fixtures use `CommittedTeamFixture` and explicit cleanup. The test-only probe verifies database/service identity before domain operations and does not run migrations. Regional delivery tests use only isolated Redis, consume/delete their envelopes and clean up those test queues. No target network requests or development worker queues are used.


## HTTP and keyword checker coverage (M3.03)

[HTTP-CHECKERS.md](HTTP-CHECKERS.md) documents three new suites: pure strategy/stream tests, HTTP/DNS/clock-faked coordinator tests and bounded loopback transport probes. The nonblocking test server only releases its two barrier responses after both requests have arrived, proving concurrency without timing-ratio assertions. It also serves a compressed oversized body and isolates a hanging request from a healthy response. These are explicit synthetic local transport probes; ordinary semantic tests remain network-free. All sockets, resources, child processes and hit logs are cleaned up; no external DNS or target network is used.


## TLS/DNS checker verification (M3.04)

`NetworkCheckStrategyTest` and `NetworkCheckCoordinatorTest` cover pure expiry/set rules, tenant admission, safe failures, aliases, deadline boundaries, cancellation and the HTTP guard's new bounded resolver. `NetworkCheckTransportTest` starts bounded synthetic loopback TLS/DNS child servers. Temporary CA/leaf certificates and keys are generated with PHP OpenSSL, injected only into the adapter under test and removed after server shutdown. Real probes cover certificate trust/hostname/expiry, stalled TLS, TCP refusal, UDP-to-TCP continuation, timeout isolation and two-client/two-query barriers that prove concurrency without elapsed-time assertions. No external DNS or monitored endpoint is contacted.

Run these files with `docker compose run --rm php-test php vendor/bin/pest tests/Feature/NetworkCheckStrategyTest.php tests/Feature/NetworkCheckCoordinatorTest.php tests/Feature/NetworkCheckTransportTest.php`; the acceptance gate remains `./scripts/verify.sh`. See [TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md).


## Heartbeat verification (M3.05)

HeartbeatStrategyTest exercises pure rolling-window rules without infrastructure. HeartbeatTest uses isolated PostgreSQL for ingress, credentials, tenancy/policies, plan/pause behavior, receipt history, migration backfill, deduplication, rollback and traffic limits. HeartbeatConcurrencyTest uses committed synthetic fixtures and real local HTTP servers behind a PostgreSQL lock barrier to check concurrent receipts, token changes and lifecycle state revalidation. No external job or real customer is contacted; child servers and committed fixtures are cleaned up. HeartbeatPanel.test.tsx verifies one-time credential handling, CSRF mutations, permissions, confirmation, copy/hide, abort and errors. See [HEARTBEATS.md](HEARTBEATS.md); the full acceptance command remains `./scripts/verify.sh`.


### Heartbeat Inertia asset-version regression

The one-time credential privacy test runs both with the current asset version and with an explicitly empty temporary public directory. It uses the real final middleware and asserts that a missing manifest produces null before normalizing the request header to an empty string, matching Inertia's server version. The temporary directory is removed when that application instance is destroyed; developer build output is never renamed or deleted. To reproduce the original CI condition, mount an empty temporary directory over `/var/www/html/public/build` only in a disposable php-test container and run HeartbeatTest.


## Integrated engine acceptance (M3.08)

`MonitorEngineTest` exercises the production initializer/reservation, outbox publisher and real scalar Redis delivery before checking committed result semantics in PostgreSQL. No production gate or application code changes are needed. The following matrix includes existing suites where their criteria were already established; M3.08 adds integrated coverage rather than duplicating protocol unit tests.

| Criterion | Integrated evidence and existing regression coverage |
| --- | --- |
| HTTP/keyword sequences, errors and recovery | `MonitorEngineTest`: four scheduled slots, `Http::fakeSequence()`, success → HTTP 503 → cURL timeout → success; stable cadence, one result per execution, broker owner/attempt, current summary, safe errors and no target retry. Laravel records only response pairs, so fake resolver calls independently count attempts including rejected connections. |
| TLS/DNS failures and recovery | `MonitorEngineTest`: TLS verification/timeout and DNS missing-record/timeout followed by a healthy next slot, with counted transport attempts and restored TLS expiry. `NetworkCheckTransportTest` retains real trust/hostname/expiry, DNS UDP/TCP and silent-resolver probes. |
| Heartbeat expiry and tolerance | `MonitorEngineTest`: grace 0/7 seconds and deadline offsets −1/0/+1 microsecond through bearer ingress, actual reservation slots and delayed delivery. A ping received after the scheduled reference does not repair that slot; the following slot sees it and recovers. |
| Pause/resume and concurrent mutation | `MonitorEngineTest`: a published job cancelled after pause without target traffic, resume initializes future work, and an authorized configuration update inside target acquisition leaves an old-revision historical result without replacing the current summary. The next observation uses the new configuration. |
| Slow target and regional independence | `MonitorWorkerIsolationTest`: EU waits on the first request to a gated loopback endpoint while a separate US worker completes the same monitor's healthy second request. US alone leaves the regional summary unknown; explicit gate release lets EU complete and yields agreement. `HttpCheckTransportTest` retains real target-timeout isolation and a two-request HTTP/keyword barrier. |
| Persistent worker tenancy | `MonitorWorkerIsolationTest`: one real Redis worker processes tenant A success, tenant B infrastructure exception, a forged B envelope pointing at A, then another A success. Fake transport checks the active tenant while acquiring; terminal worker events verify a clean context after each delivery, one process ID, exact target attempts, broker ownership, private result reads and tenant-local summaries. |
| Shared Redis monitor lock and outbox recovery | `MonitorWorkerIsolationTest`: a Business fixture uses two legitimate adjacent 30-second slots. One subprocess holds the first slot in network acquisition; another discards the later delivery under the shared Redis cache lock, leaving its row pending with no broker owner or second request. After gate release, the real publisher redelivers it and exactly one later observation is persisted. |
| Jitter and scheduling safety | `MonitorSlotsTest`: a fixed cohort of 6000 IDs covers every phase of 30/60/300/47-second intervals; all computed slots retain phase after 1000 periods and timezone conversion across the DST boundary. Existing scheduling tests cover chunking, latest-slot-only dispatch, transactional reservation, lost/duplicate messages and acquisition/recovery races. |
| Infrastructure termination and result identity | `MonitorRunnerTest` and `MonitorSchedulingConcurrencyTest`: real exception, timeout and SIGKILL handling, fresh failed-hook ownership, late-result fencing, atomic rollback, concurrent recovery, immutable results and monthly partition creation/identity. |

The worker probe adds `pipeline` and `loopback` modes plus an optional maximum delivery count, preserving its original failure/timeout modes. `EngineWorkerProbe` passes only guarded test PostgreSQL/Redis coordinates, a shared test Redis cache prefix and an explicitly allowed synthetic loopback origin. Loopback mode forbids other HTTP destinations. Worker events expose scalar UUID/PID/context/attempt-count diagnostics, never monitor configuration. Queue names are unique to each probe, except the deliberate `checks:eu` outbox redelivery, which is cleaned explicitly.

The nonblocking HTTP fixture supports an explicit release file: `/gate` holds requests until release; `/gate-first` holds the first request while subsequent requests complete normally. Readiness and request markers synchronize parent tests with real acquisition. Concurrency assertions use observed state and gate release, not elapsed-time ratios. Existing short deadlines remain safety bounds, not throughput guarantees. All workers, servers, files, queues and committed database fixtures have cleanup paths.

Run the integrated suites with:

```sh
docker compose run --rm php-test composer test -- --filter='MonitorEngine|MonitorWorkerIsolation|MonitorSlots' --fail-on-skipped
```

The acceptance gate remains `./scripts/verify.sh`, followed by the complete backend suite with an empty read-only directory mounted over `/var/www/html/public/build` in a disposable php-test container. Use `--fail-on-skipped` for that final Pest run. Keep the development engine disabled; no migration, dependency update or development-process restart belongs to M3.08. This matrix verifies deterministic behavior on synthetic services; it does not constitute a production benchmark, remote CI result or integrated PHP 8.5 verification.


## Manual browser smoke before M4

[MANUAL-BROWSER-TESTS.md](MANUAL-BROWSER-TESTS.md) provides the Italian operator checklist: environment and accounts, roles, synthetic inputs, browser/DevTools steps, expected outcomes, optional engine activation, result recording and cleanup. Manual execution remains unconfirmed until its per-case log is filled in; automated test results do not imply a browser run.


## Incident model verification (M4.01)

`IncidentStatusTest` and `IncidentSeverityTest` cover the full status transition matrix, the lifecycle configuration and severity derivation for every `CheckError` without infrastructure. `IncidentModelTest` uses isolated PostgreSQL for tenant isolation, cross-team composite foreign keys, the single-open-incident partial index, every lifecycle CHECK, enum/constraint parity, model transition guards, counted reopenings, the append-only timeline trigger and cascades. Concurrent opening is exercised by the M4.03/M4.06 actions, not here. Run them with `docker compose run --rm php-test vendor/bin/pest tests/Unit/IncidentStatusTest.php tests/Unit/IncidentSeverityTest.php tests/Integration/IncidentModelTest.php`; the acceptance gate remains `./scripts/verify.sh`. See [INCIDENTS.md](INCIDENTS.md).


## Regional confirmation and incident lifecycle verification (M4.02–M4.03)

`RegionalSequenceTest` and `ConfirmationRuleTest` cover the pure sequence and verdict rules. `IncidentDetectionTest` drives real reservations and `ExecutionLedger` terminal transitions with a fixed clock (`CarbonImmutable::setTestNow`) and faked incident events; `ManageIncidentsTest` covers the manual actions and their policy. Run them with `docker compose run --rm php-test vendor/bin/pest tests/Unit/RegionalSequenceTest.php tests/Unit/ConfirmationRuleTest.php tests/Integration/IncidentDetectionTest.php tests/Integration/ManageIncidentsTest.php`; the acceptance gate remains `./scripts/verify.sh`. See [INCIDENTS.md](INCIDENTS.md).


## Maintenance and pause history verification (M4.04)

`TimeIntervalTest` covers pure interval merging. `MaintenanceWindowTest`, `PausePeriodTest` and `ExclusionPeriodsTest` run on PostgreSQL for scheduling rules, the pause trigger and excluded time; `IncidentDetectionTest` covers the effect of maintenance on confirmation. Pause instants come from the database transaction clock, not the faked application clock. Run them with `docker compose run --rm php-test vendor/bin/pest tests/Unit/TimeIntervalTest.php tests/Integration/MaintenanceWindowTest.php tests/Integration/PausePeriodTest.php tests/Integration/ExclusionPeriodsTest.php tests/Integration/IncidentDetectionTest.php`; the acceptance gate remains `./scripts/verify.sh`. See [MAINTENANCE.md](MAINTENANCE.md).


## Incident and maintenance pages verification (M4.05)

`IncidentPagesTest` drives the production routes on PostgreSQL with a fixed clock (`CarbonImmutable::setTestNow`) for listing, projections, every incident change, maintenance input in UTC and phases, refusals and scoped bindings. `TeamAuthorizationMatrixTest` includes the ten new routes. `tests/frontend/Incidents.test.tsx` covers the pages and the maintenance panel with the Inertia router intercepted; maintenance expectations are computed with `Date` so they hold in any test timezone. Run them with `docker compose run --rm php-test vendor/bin/pest tests/Integration/IncidentPagesTest.php tests/Integration/TeamAuthorizationMatrixTest.php` and `docker compose run --rm node npx vitest run tests/frontend/Incidents.test.tsx`; the acceptance gate remains `./scripts/verify.sh`. See [INCIDENT-UI.md](INCIDENT-UI.md).


## Incident milestone matrix verification (M4.06)

`IncidentLifecycleMatrixTest` drives outcome sequences through the real reservation and ledger with a fixed clock: single-region threshold, interruption, recovery, reopening and flapping rows, two-region agreement rows, maintenance and late results of older slots (acquired before expiry, finished after the next slot). `IncidentConcurrencyTest` starts `tests/Fixtures/RunIncidentProbe.php` children against committed fixtures (`CommittedTeamFixture`), holds the team row lock until every child waits on it in `pg_stat_activity`, then releases them together. Run them with `docker compose run --rm php-test vendor/bin/pest tests/Integration/IncidentLifecycleMatrixTest.php tests/Integration/IncidentConcurrencyTest.php`; the acceptance gate remains `./scripts/verify.sh`. See [INCIDENTS.md](INCIDENTS.md#milestone-acceptance-m406).


## Notification channels and policies verification (M5.01)

`ChannelConfigurationParserTest` (unit) covers normalization, masking, refusals that never echo input, kept secrets on edit and stored-value hydration. `NotificationChannelsTest` and `EscalationPoliciesTest` run the actions on PostgreSQL with a fixed clock and `Tests\Support\RecordingVerificationSender`: encrypted storage, role checks, limits, the code lifecycle (expiry instant, durable wrong-attempt counter, discard on the fifth mistake, resend cooldown, withdrawal after a failed delivery, unavailable default sender), reset on destination change, activation, key rotation, deletion guarded by policies, step rules, single default, override/default/none resolution and database constraints (each violation in its own savepoint). `NotificationPagesTest` drives the HTTP layer: masked projections, `config.<field>` errors without flashing secrets, `channel`/`policy` refusals, the verification flow, monitor escalation props and incident deliveries. `TeamAuthorizationMatrixTest` includes the twelve routes. `tests/frontend/Notifications.test.tsx` covers the page, verification controls, secret rotation, the policy editor, the monitor escalation panel and the delivery log. The rollback tests of `MonitorModelTest` and `TeamIdentityTest` now reverse the two notification migrations first. Run them with `docker compose run --rm php-test vendor/bin/pest tests/Unit/ChannelConfigurationParserTest.php tests/Integration/NotificationChannelsTest.php tests/Integration/EscalationPoliciesTest.php tests/Integration/NotificationPagesTest.php` and `docker compose run --rm node npx vitest run tests/frontend/Notifications.test.tsx`; the acceptance gate remains `./scripts/verify.sh`. See [NOTIFICATIONS.md](NOTIFICATIONS.md).
