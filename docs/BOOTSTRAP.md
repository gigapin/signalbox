# Application bootstrap reference

M0.02 introduces the Laravel 13 application skeleton with Inertia 2, React 19, TypeScript and Tailwind 4. See [TASKS.md](TASKS.md) for remaining work and [DEPENDENCIES.md](DEPENDENCIES.md) for package constraints. The framework configuration and initial migrations were adapted from the [official Laravel 13 skeleton](https://github.com/laravel/laravel/tree/13.x); project-specific source documentation and entry points were added locally.

## Application structure

| Path | Responsibility |
|---|---|
| `artisan` | Load Composer and run a console request through Laravel. |
| `public/index.php` | Honor maintenance mode and dispatch incoming HTTP requests. |
| `bootstrap/app.php` | Register browser/console routes, framework liveness, Inertia middleware and the standard exception handler. |
| `bootstrap/providers.php` | Register the application's service provider. |
| `app/Providers/AppServiceProvider.php` | Extension point for later domain bindings and boot behavior. |
| `app/Http/Controllers/HomeController.php` | Select the public `Home` Inertia page. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share the public product name and select the Blade document shell. |
| `app/Models/User.php` | Baseline user identity, password hashing and protected serialization; team rules are M1. |
| `database/factories/UserFactory.php` | Generate non-production synthetic user identities. |
| `database/seeders/DatabaseSeeder.php` | Explicit seeding entry point; currently creates no accounts. |
| `database/migrations` | Unexecuted baseline framework schemas for users, reset tokens, sessions, cache and queue storage. |
| `routes/web.php` | Public home route; authentication and monitoring routes are not implemented. |
| `routes/console.php` | Reserved command and scheduling extension point for later milestones. |
| `resources/views/app.blade.php` | Accessible HTML document with Vite and Inertia directives. |
| `resources/js/app.tsx` | Discover React pages at build time and mount the Inertia client. |
| `resources/js/pages/Home.tsx` | Initial product identity and explicit development status, with no invented monitoring metrics. |
| `resources/js/vite-env.d.ts` | Supply Vite's `import.meta` and asset type declarations. |
| `resources/css/app.css` | Compile Tailwind utilities from project React/Blade sources. |
| `vite.config.ts` | Integrate the TSX entry point with Laravel, React and Tailwind. |
| `tests/Feature/BootstrapTest.php` | Verify initial HTML, JSON protocol, liveness and missing routes. |

## Environment and service boundaries

Copy `.env.example` to `.env` once, then generate the local key with `php artisan key:generate`. Each environment variable has an English comment describing its purpose. The template contains no real credentials. The generated key stays in the ignored local `.env`; the static key in `phpunit.xml` is public test data.

PostgreSQL is the default database driver, and Predis is the selected Redis client. M0.02 does not create a database, run migrations, connect to Redis, configure Stripe, or start workers. File-based sessions/cache and synchronous jobs allow the initial page to boot before infrastructure exists. M0.03 must configure the development infrastructure and move cache/queues to Redis; M0.04 must configure the installed ecosystem packages. The synchronous queue is a temporary bootstrap choice, not the monitoring engine architecture.

Inertia loads `resources/views/app.blade.php` on the initial visit and returns JSON on subsequent visits with `X-Inertia: true`. The client echoes the initial page's asset version in `X-Inertia-Version`; a stale or missing version can legitimately trigger a 409 reload response after assets are built. Runtime and test page discovery both use `resources/js/pages` and `.tsx` files. SSR is explicitly disabled until an SSR service is introduced.

`/up` is Laravel liveness only. It does not prove database, Redis or worker readiness; infrastructure health checks remain M11. Installed packages may register their own routes via discovery, but no team or token authorization feature is considered implemented.

## Composer manifest additions

These fields extend the baseline documented in DEPENDENCIES.md without changing dependency constraints or resolved versions.

| Field | Meaning |
|---|---|
| `autoload` | Production class-discovery configuration. |
| `autoload.psr-4` | Map PHP namespace prefixes to source directories. |
| `autoload.psr-4.App\` | Load application classes from `app/`. |
| `autoload.psr-4.Database\Factories\` | Load synthetic data factories from `database/factories/`. |
| `autoload.psr-4.Database\Seeders\` | Load explicit seeders from `database/seeders/`. |
| `autoload-dev` | Additional class loading for development and tests. |
| `autoload-dev.psr-4.Tests\` | Load test support classes from `tests/`. |
| `scripts` | Local commands and lifecycle hooks; no automatic database migration is configured. |
| `scripts.post-autoload-dump` | Clear stale framework metadata through ComposerScripts and discover package providers after autoload generation. |
| `scripts.dev` | Run the PHP development server on loopback; Vite runs separately with npm. |
| `scripts.test` | Execute the project Pest suite. |
| `scripts.format` | Apply the configured PHP-CS-Fixer rules. |
| `scripts.format:check` | Check PHP style with dry-run and show proposed changes without rewriting files. |
| `scripts.analyse` | Run PHPStan/Larastan using the project configuration and a 512 MB memory limit. |

## npm manifest additions

| Field | Meaning |
|---|---|
| `scripts` | Commands for the initial frontend toolchain. |
| `scripts.dev` | Run the Vite development server on loopback, alongside the PHP server. |
| `scripts.build` | Fail on TypeScript errors, then emit versioned production assets. |
| `scripts.types:check` | Check TypeScript without generating application assets. |

The existing package-lock graph remains valid because no dependency constraint changed. The build emits files to ignored `public/build`; the generated Vite manifest maps entry/module names to hashed JavaScript/CSS assets, imports and entry metadata. Vite owns this generated JSON; do not edit it manually.

## tsconfig.json reference

| Field | Meaning |
|---|---|
| `compilerOptions` | Shared compiler settings for browser source and Vite configuration. |
| `compilerOptions.target` | Compile against ES2022 language features. |
| `compilerOptions.lib` | Include ES2022 and browser DOM/iterable type declarations. |
| `compilerOptions.module` | Preserve modern ECMAScript module imports for Vite. |
| `compilerOptions.moduleResolution` | Resolve packages using bundler-aware semantics. |
| `compilerOptions.jsx` | Use React's automatic JSX runtime. |
| `compilerOptions.strict` | Enable TypeScript's strict type-checking family. |
| `compilerOptions.noEmit` | Leave asset emission to Vite. |
| `compilerOptions.isolatedModules` | Require files to support independent transformation. |
| `compilerOptions.esModuleInterop` | Support interoperable package import syntax. |
| `compilerOptions.skipLibCheck` | Skip checking dependency declaration internals while checking application usage. |
| `compilerOptions.forceConsistentCasingInFileNames` | Reject imports whose casing disagrees with their paths. |
| `compilerOptions.noUncheckedIndexedAccess` | Require handling absent entries in maps, including dynamic page loaders. |
| `compilerOptions.resolveJsonModule` | Allow typed JSON imports when needed. |
| `compilerOptions.types` | Include Vite client and Node declarations. |
| `include` | Restrict checking to application TypeScript/TSX and `vite.config.ts`. |

## Quality and test configuration

PHP-CS-Fixer scans application-owned PHP, migrations, routes, tests and entry points. It excludes dependency/generated cache directories, uses PSR-12 plus explicit short-array/import/string rules, preserves explanatory comments and disables risky rules. Its generated cache is ignored. On local PHP 8.5 it warns that the project minimum is PHP 8.4; real PHP 8.4 execution remains required in Docker/CI.

Larastan runs at level 8 on application classes, bootstrap, database helpers/migrations and routes. Its local cache is ignored. This baseline does not complete M0.05: frontend lint/format tooling and the Rector evaluation remain open.

`phpunit.xml` selects HTTP feature tests, loads Composer, limits source coverage discovery to `app/`, and forces test environment settings. Test cache/session/mail use memory, the queue is synchronous, logging is discarded, and PostgreSQL remains the selected database driver. These four bootstrap tests do not access a database; PostgreSQL integration, unit suites and frontend interaction tests are M0.06 and feature work. No SQLite fallback has been introduced.

The English comments in source document files, classes, methods, callbacks, props, parameters and variables. JSON fields are documented here or in DEPENDENCIES.md because JSON does not accept comments. `.editorconfig` standardizes encoding, newlines and indentation; `.gitattributes` retains the upstream text normalization rules.

## Local execution and verification

Follow the commands in [README.md](../README.md). Git is initialized on `main`; no commit or remote was created. Environment files, dependencies, build output, logs and quality-tool caches remain ignored.

Verified on local PHP 8.5.9 and Node 22.23.2:

- Composer install from the existing lock, autoload generation, package discovery and Artisan environment boot.
- Four Pest feature tests, with 26 assertions.
- TypeScript strict checking and Vite production compilation.
- Larastan level 8 without errors.
- PHP-CS-Fixer formatting and subsequent dry-run.
- Real HTTP responses for the initial document, versioned Inertia JSON and `/up`; all three compiled JavaScript/CSS assets served successfully. The temporary server was stopped after the check.

No real browser execution, frontend interaction suite, PostgreSQL/Redis test, migration execution, PHP 8.4 runtime check or GitHub Actions run is claimed. Those checks remain in their owning milestones.

## M0.03 infrastructure follow-up

[DOCKER.md](DOCKER.md) documents the Compose runtime, internal database/Redis addresses, process environment overrides, explicit migration/startup commands and opt-in real-service tests. Host bootstrap defaults remain file/sync; Compose selects Redis sessions/cache/queues. Historical M0.02 verification above describes the state at that milestone, not the current Git remote or infrastructure state.
