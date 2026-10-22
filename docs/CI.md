# Continuous integration

M0.07 adds `.github/workflows/ci.yml`. It runs the existing local verification commands as separate steps so GitHub identifies the failing gate. Runtime packages and lockfiles are unchanged. PHP 8.4 remains the deployment baseline.

## Events and visible checks

| Event | Backend — PHP 8.4 | Backend — PHP 8.5 | Frontend — Node 22 |
|---|---|---|---|
| Pull request to any branch, including main | Run | Skip | Run |
| Push to staging | Run | Skip | Run |
| Push to main, including a merge | Run | Run | Run |
| Manual dispatch on main | Run | Run | Run |
| Manual dispatch on another branch | Run | Skip | Run |
| Push to a working branch without a PR | No workflow | No workflow | No workflow |

Decision explicitly requested by the user: check PHP 8.5 only on main to limit PR runner usage. Compatibility failures can therefore be discovered after merging to main. PHP 8.5 has no `continue-on-error`; its failures are real CI failures. Jobs are independent, with no dependency that cancels one runtime when another fails. A failed step stops the remaining steps in that job.

The manual Run workflow control requires the workflow to be present on the repository's default branch. Before then, opening a PR exercises PHP 8.4 and frontend; merging into main exercises all three jobs. No repository settings or Git operations are performed by the agent.

## Backend gates

Both backend jobs run on Ubuntu 24.04 with their respective PHP minor, Composer 2.10.2 and explicitly configured bcmath, pcntl, pdo_pgsql, posix, intl, zip and mbstring extensions. Coverage drivers are disabled.

| Step | Command / acceptance |
|---|---|
| Manifest and lock consistency | `composer validate --strict --no-check-publish` |
| Locked dependency installation | `composer install --prefer-dist --no-interaction --no-progress`, including development packages and package discovery |
| Actual runtime compatibility | `composer check-platform-reqs`, checking installed extensions and real PHP independently of `config.platform.php` |
| PHP formatting | `composer format:check -- --sequential`, dry-run with diff |
| Static analysis | `composer analyse -- --debug`, configured Larastan level 8 without a new baseline |
| All backend tests | `composer test -- --fail-on-skipped`, Unit/Feature/Integration without silently skipped infrastructure checks |

Each job provisions fresh PostgreSQL 16 and Redis 7 service containers using the exact image digests already pinned in Compose. Health checks wait for `pg_isready` and `redis-cli ping`. Ports 5432/6379 are mapped to that job's disposable runner; hosts are `127.0.0.1`. PostgreSQL database, account and password are the public `signalbox_test` fixtures.

`RUN_DOCKER_TESTS=1` opts into real integrations even though PHP runs directly on the runner. `phpunit.xml` supplies forced test identities, connection settings and fake package credentials. The existing pre-migration guard verifies the server identity before `RefreshDatabase` migrates the dedicated database. Tests run sequentially; separate jobs do not share their database or Redis. Services disappear with the job and never use local development data.

Before Composer installation, both backend jobs create an empty `.env.testing`. Runtime values still come from the job environment and PHPUnit's isolated settings; no developer `.env` is copied, private key generated or Laravel configuration cached. The empty file prevents phpdotenv's missing-file reads from becoming PHPUnit warnings on fresh runners. A public fixture APP_KEY and testing environment allow package discovery without application secrets. YAML anchors reuse backend services and steps between the two jobs; their PHP versions remain explicit per-job environment values.

Backend jobs do not build frontend assets or receive the independent frontend job's build output. `ContainerWebEnvironmentTest` therefore requests the login page through the Inertia JSON protocol, with the current asset version (empty when no build exists), before exercising the real CSRF-protected login POST against PostgreSQL. It does not render the Vite-dependent HTML shell. The isolated HTTP process reports errors through stderr, included in failure diagnostics without printing response bodies or cookies; it is stopped in `finally`.

## Frontend gates

Node 22.23.2 satisfies the project's minimum 22.22.2. npm is explicitly selected as 10.9.8 to match the recorded package manager.

| Step | Command |
|---|---|
| Locked installation with strict peer resolution | `npm ci --ignore-scripts --strict-peer-deps` |
| Typed ESLint, React hooks and zero warnings | `npm run lint` |
| Prettier without rewriting files | `npm run format:check` |
| Strict TypeScript | `npm run types:check` |
| Quality-tool regression tests | `npm run test:quality` |
| React DOM and keyboard interaction tests | `npm test` |
| Production TypeScript/Vite build | `npm run build` |

These are the commands aggregated by `npm run verify`. Backend gates likewise match `composer verify`, with the additional CI requirement that skipped tests fail. The frontend job does not need PHP, PostgreSQL, Redis or a running application server. Build output is disposable verification output, not a deployment artifact.

## Execution policy and validation

- Every job has a 20-minute timeout. New runs cancel obsolete runs for the same event and PR/ref; a PR does not cancel the push workflow for its target branch.
- The token has only `contents: read`; checkout does not persist credentials. There are no production secrets, write permissions or deployment steps.
- Checkout v7, setup-node v7, cache v5 and setup-php v2 are pinned to verified public commit SHAs, annotated by major. Update pins explicitly and review upstream changes.
- Composer archive caches include OS, architecture, PHP minor and the lock hash. setup-node caches npm downloads against the npm lock. Dependencies are installed on every run, including cache hits; neither vendor/node_modules nor Laravel/test caches are restored.
- There are no path filters: documentation-only PRs also receive the same required checks.
- Audit gates, coverage targets, browser E2E, Docker image builds and deployment are outside this task. No new dependency update or application API change is included.

Validate the workflow with [actionlint](https://github.com/rhysd/actionlint):

```sh
actionlint .github/workflows/ci.yml
```

The implementation session used actionlint 1.7.12 from an official release archive with its published SHA-256 checksum verified. It is a local validation tool, not an added application dependency or fourth CI job. Local checks cannot prove GitHub runner provisioning, action execution or branch-event delivery; remote results remain required before marking M0.07 complete. See [TASKS.md](TASKS.md) for actual evidence.

For branch protection, PHP 8.4 and frontend are the checks available on PRs. Do not require successful execution of PHP 8.5 before merge: that job deliberately skips PR events. Repository rules remain user-managed.

References: [GitHub service containers](https://docs.github.com/en/actions/tutorials/use-containerized-services/create-postgresql-service-containers), [YAML anchors](https://docs.github.com/en/actions/reference/workflows-and-actions/reusing-workflow-configurations#yaml-anchors-and-aliases), [PHP setup](https://github.com/shivammathur/setup-php), [Node setup](https://github.com/actions/setup-node), and [Composer platform checks](https://getcomposer.org/doc/03-cli.md#check-platform-reqs).

