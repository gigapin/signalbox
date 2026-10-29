# Dependency compatibility and manifest reference

Verified on 2026-10-15 for M0.01. Product requirements remain in [the specification](03-Progetto-B-Signalbox-Laravel.md); work status is in [TASKS.md](TASKS.md).

## Outcome and decisions

The initial backend and frontend dependency graphs resolve successfully with stable packages. Exact versions are recorded in the root `composer.lock` and `package-lock.json`; constraints in the manifests intentionally permit compatible updates within the selected majors. At M0.01 this was a dependency baseline without an initialized application or installed packages. M0.02 subsequently added the application and installed these locked dependencies; see [BOOTSTRAP.md](BOOTSTRAP.md) for the current bootstrap state and verification evidence.

- Keep PHP 8.4 as the deployment and Composer resolution baseline; test PHP 8.5 in CI. Laravel 13 officially supports both. [Laravel release policy](https://laravel.com/docs/13.x/releases).
- Use Pest 4 and its Laravel plugin 4 instead of the specified Pest 3. Plugin 3 excludes Laravel 13; plugin 4 explicitly supports it. This is the smallest major upgrade needed for the planned Laravel test integration. [Plugin 3 manifest](https://raw.githubusercontent.com/pestphp/pest-plugin-laravel/3.x/composer.json), [plugin 4 manifest](https://raw.githubusercontent.com/pestphp/pest-plugin-laravel/4.x/composer.json). The unversioned Pest website now documents Pest 5; do not install an unconstrained latest major.
- Keep Inertia 2 on both sides and React 19. The React adapter declares React 19 support. [Inertia server manifest](https://raw.githubusercontent.com/inertiajs/inertia-laravel/2.x/composer.json), [React adapter manifest](https://raw.githubusercontent.com/inertiajs/inertia/2.x/packages/react/package.json).
- Bootstrap from the Laravel skeleton and wire Inertia explicitly. The current React starter uses Inertia 3 and would change the specified major. [Current starter manifest](https://raw.githubusercontent.com/laravel/react-starter-kit/main/package.json).
- Use Laravel's native JSON:API resource support for M10. Serialization support does not replace endpoint validation, filters, errors, policies or contract tests. [JSON:API resources](https://laravel.com/docs/13.x/eloquent-resources#jsonapi-resources).
- Use Node 22 (minimum 22.12), npm 10, Vite 8 and Laravel Vite plugin 3. Their resolved peer dependencies pass npm's strict resolution. [Laravel Vite plugin manifest](https://raw.githubusercontent.com/laravel/vite-plugin/3.x/package.json), [Vite requirements](https://vite.dev/guide/).
- Keep PostgreSQL 16 and Redis 7 as requested. Laravel supports PostgreSQL 10+, and Horizon requires Redis. Container integration and image pinning remain M0.03. [Database support](https://laravel.com/docs/13.x/database), [Horizon](https://laravel.com/docs/13.x/horizon).
- Select Predis 3 for initial portability and configure `REDIS_CLIENT=predis` during bootstrap. Benchmark the client during load testing; changing to PhpRedis requires an explicit configuration/dependency decision. [Laravel Redis clients](https://laravel.com/docs/13.x/redis).
- Keep PHP-CS-Fixer as the only reference PHP formatter. Formatter rules and CI commands remain M0.05/M0.07; no Pint dependency was added.

## Resolved backend packages

All versions below are read from `composer.lock`, including packages intended for later configuration milestones. Dependency availability does not imply a configured feature.

| Package | Constraint | Locked version | Purpose |
|---|---|---|---|
| `inertiajs/inertia-laravel` | `^2.0` | `v2.0.28` | Laravel adapter for the required Inertia 2 protocol. |
| `laravel/cashier` | `^16.0` | `v16.9.0` | Stripe subscriptions; configuration and tenant billing remain in M9. |
| `laravel/framework` | `^13.0` | `v13.36.0` | Laravel 13 application framework and native JSON:API resources. |
| `laravel/horizon` | `^5.0` | `v5.50.1` | Redis queue supervision and operational dashboard. |
| `laravel/reverb` | `^1.0` | `v1.12.0` | First-party WebSocket server for team broadcasts. |
| `laravel/sanctum` | `^4.0` | `v4.3.3` | Scoped API tokens; web authentication continues to use sessions. |
| `laravel/scout` | `^10.0` | `v10.25.0` | Search integration using the database engine initially. |
| `predis/predis` | `^3.0` | `v3.7.0` | Portable Redis client; local PHP does not provide ext-redis. |
| `fakerphp/faker` | `^1.23` | `v1.24.1` | Synthetic factory and demonstration data. |
| `friendsofphp/php-cs-fixer` | `^3.0` | `v3.95.27` | Required PHP formatter, replacing Pint as the project reference. |
| `larastan/larastan` | `^3.0` | `v3.13.0` | Laravel-aware static analysis; level 8 configuration is M0.05. |
| `mockery/mockery` | `^1.6` | `1.6.15` | Test doubles for framework integrations. |
| `pestphp/pest` | `^4.0` | `v4.7.8` | Automatic backend tests on the compatible Pest 4 major. |
| `pestphp/pest-plugin-laravel` | `^4.0` | `v4.1.0` | Laravel test integration with declared Laravel 13 support. |

Cashier 16 and Larastan 3 declare Laravel 13 compatibility. Their published stable releases resolved together with Horizon, Reverb, Sanctum and Scout. [Cashier manifest](https://raw.githubusercontent.com/laravel/cashier-stripe/16.x/composer.json), [Larastan manifest](https://raw.githubusercontent.com/larastan/larastan/3.x/composer.json).

## Resolved frontend packages

| Package | Constraint | Locked version | Purpose |
|---|---|---|---|
| `@inertiajs/react` | `^2.0.0` | `2.3.29` | Inertia 2 React adapter; must stay on the same protocol major as the server. |
| `react` | `^19.0.0` | `19.3.1` | React 19 UI runtime. |
| `react-dom` | `^19.0.0` | `19.3.1` | React 19 browser rendering, aligned with react. |
| `@tailwindcss/vite` | `^4.0.0` | `4.3.3` | Tailwind 4 asset compilation through Vite. |
| `@types/node` | `^22.0.0` | `22.20.5` | Node 22 type declarations for build configuration. |
| `@types/react` | `^19.0.0` | `19.3.0` | React 19 TypeScript declarations. |
| `@types/react-dom` | `^19.0.0` | `19.3.0` | React DOM 19 TypeScript declarations. |
| `@vitejs/plugin-react` | `^6.0.0` | `6.1.2` | React transformation for Vite 8. |
| `laravel-vite-plugin` | `^3.0.0` | `3.2.0` | Laravel asset integration for Vite 8. |
| `tailwindcss` | `^4.0.0` | `4.3.3` | Tailwind 4 styling engine. |
| `typescript` | `^5.9.0` | `5.9.3` | Static checking for the React frontend and build configuration. |
| `vite` | `^8.0.0` | `8.3.4` | Asset development server and production build tool. |

## composer.json field documentation

This section documents the comment-free JSON manifest. Package keys and their individual purposes are listed in the backend table above.

| Field | Meaning |
|---|---|
| `$schema` | Composer's schema URL for editor validation. |
| `name` | Internal application package identity, `signalbox/signalbox`. |
| `type` | `project` identifies a deployable application. |
| `description` | Human-readable description of the monitoring SaaS. |
| `license` | `proprietary` does not grant an open-source redistribution license. Revisit explicitly if the repository is published under another license. |
| `require` | Packages and platform capabilities needed in production. |
| `require.php` | Accept PHP 8.4.x or 8.5.x; additional runtimes require a tested decision. |
| `require.ext-bcmath` | Decimal arithmetic used by money-related dependencies. |
| `require.ext-pcntl` | Worker process control required by Horizon. |
| `require.ext-posix` | POSIX process facilities required by Horizon. |
| `require.ext-pdo` | PDO database abstraction used by Laravel. |
| `require.ext-pdo_pgsql` | Native PostgreSQL connectivity; SQLite alone is insufficient. |
| `require-dev` | Formatting, analysis, factory and testing tools excluded from production installs. |
| `config` | Project-local Composer behavior, without modifying global configuration. |
| `config.platform.php` | Resolve dependencies against PHP 8.4.0 even on the local PHP 8.5 runtime; this does not emulate runtime execution or extension availability. |
| `config.sort-packages` | Maintain deterministic alphabetical package ordering on future Composer changes. |
| `config.preferred-install` | Prefer release archives (`dist`) over source checkouts. |
| `config.allow-plugins` | Explicit allow-list for Composer plugins. |
| `config.allow-plugins.pestphp/pest-plugin` | Permit the Pest integration plugin during later installs; plugin execution was disabled during this resolution. |
| `minimum-stability` | Only stable releases are eligible. |
| `prefer-stable` | Favor stable versions if stability constraints are extended later. |

M0.02 added application autoload mappings and development commands once their targets existed; all added fields are documented in [BOOTSTRAP.md](BOOTSTRAP.md).

## package.json field documentation

Package keys and their individual purposes are listed in the frontend table above.

| Field | Meaning |
|---|---|
| `name` | npm identity for the local frontend package. |
| `version` | Initial internal baseline version, not a public product release. |
| `private` | Prevent accidental npm publication. |
| `type` | Interpret JavaScript configuration as ECMAScript modules. |
| `description` | Purpose of the frontend dependency manifest. |
| `engines` | Supported toolchain ranges for this project; npm may only warn unless engine enforcement is enabled later. |
| `engines.node` | Restrict runtime to Node 22, at least 22.22.2 after M0.06 jsdom (initial floor 22.12.0, M0.05 floor 22.13.0). |
| `engines.npm` | Restrict the initial package manager major to npm 10. |
| `packageManager` | Record npm 10.9.8 used to create the baseline; this field alone does not install or enforce npm. |
| `dependencies` | Inertia and React browser runtime dependencies. |
| `devDependencies` | Build, styling and type-check tooling. |

Build/dev/type-check scripts were added with the entry points in M0.02 and are documented in [BOOTSTRAP.md](BOOTSTRAP.md). Frontend test and lint tools will be added in M0.05/M0.06 with their configuration.

## Generated lockfile documentation

Do not add comments to generated JSON or edit individual transitive package records manually. Regenerate locks through the relevant package manager and review the changes. The manifests describe direct dependencies; locks record the entire resolved graph.

- `composer.lock`: `_readme` identifies the generated artifact; `content-hash` ties it to the manifest; `packages` and `packages-dev` contain release metadata, requirements, source/dist references, autoload rules and integrity information. `aliases`, stability flags and minimum/preferred stability describe resolution policy. `platform`/`platform-dev` contain runtime constraints; `platform-overrides` records the PHP 8.4 baseline; `plugin-api-version` records the Composer plugin API. Nested third-party metadata follows the [Composer schema](https://getcomposer.org/doc/04-schema.md), rather than application configuration.
- `package-lock.json`: `name`/`version` identify the root package; `lockfileVersion` specifies the npm lock format; `requires` indicates dependency tracking; `packages` maps install paths to root/dependency metadata. Records include version, registry archive, integrity hash, dependencies/peer/optional dependencies, engines, platform constraints and dev/license/funding metadata where supplied. See the [npm lockfile reference](https://docs.npmjs.com/cli/v10/configuring-npm/package-lock-json).

Keep both locks in version control. Later setup uses `composer install` and `npm ci` so sessions share the resolved versions. Any manifest changes during bootstrap must update and revalidate the corresponding lock.

## Verification evidence and limits

Observed local tools: PHP 8.5.9, Composer 2.10.2, Node 22.23.2, npm 10.9.8, Docker 29.6.1 and Compose 5.3.0. Docker daemon availability was not tested.

Executed successfully:

```text
composer update --no-install --no-scripts --no-plugins --no-interaction --prefer-dist
npm install --package-lock-only --ignore-scripts --strict-peer-deps
composer validate --strict --no-check-publish
composer check-platform-reqs --lock
```

Composer resolved 157 packages against the declared PHP 8.4 baseline. npm reported 138 audited packages and no peer conflicts. Both package managers reported zero known vulnerability advisories at resolution time; this is a point-in-time result. Platform checks passed for the actual local PHP 8.5 runtime and installed extensions, independently of the emulated PHP baseline.

At the end of M0.01 no application test, formatter run, frontend build, PostgreSQL/Redis integration test or actual PHP 8.4 execution had run: application source and dependency installations do not exist yet. PHP 8.4 runtime verification and the PHP 8.5 test matrix remain mandatory in M0.03/M0.07. Database and Redis image patch versions/digests remain to be pinned with Docker configuration.

Composer emitted a temporary-directory warning when validation ran inside the read-only sandbox; validation and platform checks still exited successfully. No global PHP or Composer settings were changed.

M0.02 follow-up: Git and the Laravel/Inertia/React skeleton are now initialized, dependencies installed, and local boot/build verified without changing resolved package versions. See [BOOTSTRAP.md](BOOTSTRAP.md). Next: M0.03, Docker with PHP 8.4, PostgreSQL 16, Redis 7 and dedicated processes.

## M0.03 container image reference

The official PHP 8.4 CLI Bookworm, Composer 2, PostgreSQL 16 Bookworm, Redis 7 Bookworm and Node 22 Bookworm slim images are pinned to multi-platform registry index digests in `docker/php/Dockerfile`, `docker/node/Dockerfile` and `compose.yaml`. Digest values were resolved with `docker buildx imagetools inspect`; update these explicitly rather than removing pins. Native PHP extensions use the official image helpers. No Composer/npm package constraint or lockfile changed. See [DOCKER.md](DOCKER.md) for service configuration, verification and rebuild limitations.

## M0.04 package configuration

No dependency version, manifest or lockfile changed. Application-owned configuration now overrides the installed package defaults for Horizon, Reverb, Sanctum, Scout and Cashier; see [SERVICES.md](SERVICES.md) for file responsibilities, environment settings, security boundaries and verification. Cashier migration adaptation is deferred until the Team billable model exists, avoiding incorrect User-owned subscriptions.

## M0.05 frontend quality dependencies and manifest reference

Node 22/npm 10 remain the toolchain. The minimum Node patch rises to 22.13.0 because ESLint 10 requires it on Node 22; Docker and the actual host runtime satisfy the requirement. Version selection was checked against official npm package metadata, [ESLint support status](https://eslint.org/version-support/), [typescript-eslint compatibility](https://typescript-eslint.io/users/dependency-versions/), [React Hooks guidance](https://react.dev/learn/editor-setup), and [Prettier installation](https://prettier.io/docs/install). No Composer constraint/lockfile or existing direct frontend dependency version changed.

| Added development dependency | Exact version | Responsibility |
|---|---|---|
| `eslint` | `10.12.0` | Maintained lint CLI and flat-configuration API. |
| `@eslint/js` | `10.0.1` | Recommended JavaScript correctness rules. |
| `typescript-eslint` | `8.71.2` | Parser, project service and typed TypeScript lint rules; compatible with TypeScript 5.9.3 and ESLint 10. |
| `eslint-plugin-react-hooks` | `7.1.1` | Official recommended hook/compiler correctness diagnostics. |
| `eslint-config-prettier` | `10.1.8` | Disable lint formatting rules that could conflict with Prettier. |
| `prettier` | `3.9.10` | Explicit frontend formatting and non-mutating format check. |
| `globals` | `17.13.0` | Browser/Node global-name definitions scoped to the relevant source files. |

The JSX accessibility plugin was considered but excluded: current 6.10.2 peer metadata supports only ESLint through 9, which is EOL. No peer override or unsupported ESLint major is retained. Accessibility remains a feature/test requirement. Rector was evaluated and not installed; the scoped rationale is in [QUALITY.md](QUALITY.md). Newly added dependencies are exact-pinned; the regenerated npm lock records their transitive graph and preserves the original direct versions.

| Added `scripts` field | Meaning |
|---|---|
| `lint` | Lint browser source, Vite config, root JS configuration and quality scripts; fail on warnings. |
| `lint:fix` | Run lint with explicit fixes; never used by a read-only check. |
| `format` | Apply Prettier only to frontend source and selected JS/TS/JSON tooling files. |
| `format:check` | Verify the same Prettier scope without rewriting files. |
| `test:quality` | Run the five Node built-in tests of real lint/compiler/formatter gates. |
| `check` | Sequentially run lint, format check, type check and quality-tool tests; production build remains separate. |

The existing `dev`, `build`, `types:check`, runtime dependencies and npm major constraints are unchanged. [QUALITY.md](QUALITY.md) documents the six new tsconfig compiler fields, formatting policy and exclusions; no comments were inserted into JSON or generated locks.

## M0.06 test dependencies and manifest reference

Compatibility was checked against official npm engine/peer metadata, [Vitest requirements](https://vitest.dev/guide/), [Testing Library setup](https://testing-library.com/docs/react-testing-library/setup/) and [jsdom](https://github.com/jsdom/jsdom). Node stays 22/npm 10; the minimum Node patch rises to 22.22.2 to meet jsdom 30.1.3. Existing host and pinned Docker runtime satisfy it. No runtime frontend dependency or Composer package constraint changes.

| Added development dependency | Exact version | Responsibility |
|---|---|---|
| `vitest` | `5.0.5` | Vite 8-compatible test runner; supports the installed Node/TypeScript types. |
| `jsdom` | `30.1.3` | DOM environment without downloading a real browser. |
| `@testing-library/react` | `16.3.3` | React 19 rendering and component queries. |
| `@testing-library/dom` | `10.4.2` | Explicit DOM peer shared by React, user-event and assertion helpers. |
| `@testing-library/user-event` | `14.6.8` | Keyboard/focus interaction simulation. |
| `@testing-library/jest-dom` | `7.0.1` | DOM matchers registered through its Vitest entry point. |

`package.json` adds `test` (non-watch Vitest), `test:watch` (explicit watch mode) and `verify` (quality checks, DOM tests, build). Lint/format scopes and tsconfig `include` now cover `tests/frontend` and `vitest.config.ts`, without relaxing existing strictness. `composer.json` adds only `scripts.verify`, sequencing the existing formatter dry-run with `--sequential`, Larastan with `--debug`, and Pest. Composer's dependency graph/content hash is unaffected by a script-only change; no lock regeneration is needed for that addition. JSON remains comment-free, with fields documented here and in [TESTING.md](TESTING.md).

Compose reuses the pinned PHP/PostgreSQL/Redis images and adds only a test profile, unpublished ephemeral service storage and public fixture credentials. No application database credential or volume is replaced. Backend test support classes are covered by the existing development autoload namespace; no new PHP package is required.

## M1.02 authentication dependency update

On 2026-10-20 M1.02 adds `require.laravel/fortify` with constraint `^1.41`, resolved to **v1.41.0** against the existing PHP 8.4 Composer platform. This is the headless authentication backend for the application's explicitly declared Inertia 2/React routes, not a starter-kit replacement. Official package compatibility: [Fortify v1.41.0 manifest](https://github.com/laravel/fortify/blob/v1.41.0/composer.json); behavior reference: [Laravel Fortify](https://laravel.com/docs/13.x/fortify).

The initial targeted require installed 14 packages and updated/removed none: Fortify, `bacon/bacon-qr-code` v3.1.1, `dasprid/enum` 1.0.7, `pragmarx/google2fa` v9.1.0, `paragonie/constant_time_encoding` v3.1.3, `laravel/passkeys` v0.2.1 and its WebAuthn/CBOR/PKI/Symfony serialization dependencies. The exact transitive graph is owned by composer.lock. Passkeys are required transitively by this release but are not enabled, migrated or exposed as authentication features. No npm dependency or lock change is required.

The require surfaced a real advisory on **pre-existing** `symfony/http-foundation` v8.0.15: [CVE-2026-108082](https://symfony.com/cve-2026-108082), concerning Cache-Control header parsing. A targeted `composer update symfony/http-foundation --with-dependencies --minimal-changes --no-interaction --no-scripts --no-plugins --prefer-dist` changed only that package to **v7.4.21**. This supported LTS branch is allowed by Laravel 13's existing `^7.4.0 || ^8.0.0` constraint and avoids the affected range; no direct constraint or required product stack major was changed. This is an explicit security adjustment, not a silent general dependency refresh. Composer then reported no security advisories. Actual full quality/integration results and limits are recorded in [TASKS.md](TASKS.md).

[AUTHENTICATION.md](AUTHENTICATION.md) documents the application-owned Fortify configuration, permitted features, request/response interfaces, encrypted fields, replay handling and local notification behavior. Application providers disable package routes before boot; package publication is not run over existing application files.

## Optional pgAdmin image (2026-10-23)

User request: the optional local `pgadmin` service uses `dpage/pgadmin4:9.18.0` pinned to `sha256:c332c5f6dfba995d9ebc4af261d93506d6876085d712eaaa3defc8dd1a3f26de`, resolved with `docker buildx imagetools inspect` (linux/amd64 and linux/arm64). It is a development tool behind the `dbadmin` profile and changes no application dependency, lockfile or CI job. Configuration and limits are in [DOCKER.md](DOCKER.md#optional-pgadmin).

## PHP 8.4.27 security update (2026-10-23)

User request for a security update: `docker/php/Dockerfile` moves from `php:8.4-cli-bookworm@sha256:836ac6c6…`, which ran PHP 8.4.26, to `php:8.4.27-cli-bookworm@sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05`, resolved with `docker buildx imagetools inspect`. The php.net 8.4.27 changelog lists core fixes for GHSA-w8hw-w9fx-j79w and GHSA-6wmf-4rv5-p3rp (state corruption via bailout) plus several use-after-free fixes. The tag now names the patch explicitly. Composer constraints (`~8.4.0 || ~8.5.0`, platform 8.4.0), lockfiles and extensions are unchanged. CI installs `PHP_VERSION: '8.4'` through setup-php, which selects the latest patch it ships; the patch actually used by CI must be read from its job log.



## M3.04 direct transport dependencies

TLS/DNS acquisition directly uses existing ReactPHP dependencies previously installed transitively through Reverb. They are now declared explicitly in `composer.json`: `react/dns` ^1.14 (locked v1.14.0), `react/event-loop` ^1.6 (v1.6.0), `react/promise` ^3.3 (v3.3.0), `react/promise-timer` ^1.11 (v1.11.0), and `react/socket` ^1.17 (v1.17.0). `ext-openssl` * makes the existing runtime capability an explicit platform requirement for certificate verification and test certificate generation. These additional `require` keys describe application-owned transport use; no package version was updated. Composer synchronized the lock metadata with `composer update --lock --no-install --no-scripts --no-audit --no-interaction`, reporting nothing to modify in the locked package graph.

The adapters compose cancellable operations on private loops; DNS uses explicit UDP/TCP executors with one timeout, avoiding the factory's default retries, hosts-file handling and cache. See [TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md), [official DNS documentation](https://reactphp.org/dns/), [Socket documentation](https://reactphp.org/socket/), and [PHP TLS options](https://www.php.net/manual/en/context.ssl.php). No Docker image, framework major, npm manifest or npm lockfile changes.


## M3.05 heartbeat compatibility

Heartbeat uses the existing Laravel/PostgreSQL/React stack and PHP random_bytes/hash primitives. No manifest, lockfile, package version or runtime extension changes. `config/heartbeat.php` is PHP configuration, with mandatory positive IP/credential requests-per-minute limits documented in [HEARTBEATS.md](HEARTBEATS.md).
