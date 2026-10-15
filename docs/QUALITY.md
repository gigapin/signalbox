# Code quality baseline

M0.05 completes local quality tooling. Application behavior, tenant authorization and runtime safety still need their own tests; lint and formatting are not substitutes. See [TASKS.md](TASKS.md) for M0.06 test work and M0.07 CI, and [DEPENDENCIES.md](DEPENDENCIES.md) for exact resolved package versions.

## Commands and ownership

| Command | Responsibility | Rewrites source? |
|---|---|---|
| `composer format` | Apply the versioned PHP-CS-Fixer rules. | Yes, PHP only. |
| `composer format:check -- --sequential` | Run the PHP formatter in dry-run mode, without worker sockets. | No. |
| `composer analyse -- --debug` | Run Larastan level 8 without parallel worker sockets. | No. |
| `composer test` | Run Pest; real-service tests require explicit opt-in. | No application source; test runtime caches may be written. |
| `npm run lint` | Typed ESLint and React Hooks checks, failing on warnings. | No. |
| `npm run lint:fix` | Apply the lint rules' available safe fixes. | Yes, selected frontend/tooling files. |
| `npm run format` | Apply Prettier to frontend source and its configuration. | Yes, selected frontend/tooling files. |
| `npm run format:check` | Detect formatting drift without applying changes. | No. |
| `npm run types:check` | Run the strict TypeScript compiler without emitting assets. | No. |
| `npm run test:quality` | Verify quality gates against valid and deliberately invalid synthetic source. | Creates and removes temporary test fixtures. |
| `npm run check` | Sequential lint, formatting check, TypeScript check and quality-tool tests. | No permanent source changes. |
| `npm run build` | Run TypeScript checking, then compile production assets. | Generated `public/build` only. |

PHP-CS-Fixer is the **only PHP formatter**. It replaces Pint from the original specification by the user's explicit instruction. Pint is not installed and no second PHP formatting configuration is introduced. The existing PSR-12-based configuration disables risky transformations and preserves explanatory documentation. It covers application PHP, configuration, migrations, tests, routes and entry points, excluding generated/dependency files.

Larastan remains at level 8, with no new baseline or suppressed errors. Its configured application/bootstrap/database/route paths are unchanged. PHP configuration is reviewed and formatter-checked; not every configuration file or test fixture is part of Larastan's configured analysis paths. `--debug` disables parallel execution and provides per-file diagnostics. Outside constrained environments, the existing `composer analyse` command can still run normally.

Use PHP 8.4 to match the minimum runtime. Local PHP 8.5 also runs the checks but PHP-CS-Fixer warns about its newer runtime. Docker verification uses the project's actual PHP 8.4 image.

## Frontend linting

`eslint.config.js` uses ESLint 10 flat configuration and typescript-eslint's `recommendedTypeChecked` rules, with the TypeScript project service. It covers `resources/js/**/*.ts`, TSX and declarations, plus `vite.config.ts`. Type-aware checks catch unsafe values and unhandled promises. Detached promises must be explicitly acknowledged, for example by the existing Inertia mount's `void` expression; this does not handle a rejected promise automatically.

The official React Hooks recommended configuration applies to browser source, including unconditional hook ordering and dependency checks. Browser globals apply to React modules; Node globals apply to Vite and the JavaScript configuration/test tooling. Type-only imports are enforced. The lint command exits unsuccessfully on any warning. Generated bundles, dependencies, runtime storage and PHP caches are globally ignored.

ESLint 9 is [end of life](https://eslint.org/version-support/). The current `eslint-plugin-jsx-a11y` 6.10.2 declares peer support only through ESLint 9, so it is deliberately excluded rather than installing incompatible peers or an unmaintained lint major. This baseline does **not** claim JSX accessibility lint coverage. Accessibility, keyboard interaction and rendered UI tests remain required in M0.06 and each feature milestone; a compatible static plugin can be added in a separately verified update. See [typescript-eslint compatibility](https://typescript-eslint.io/users/dependency-versions/) and [React editor guidance](https://react.dev/learn/editor-setup).

The tooling requires Node 22 **at least 22.13.0**, raised from the earlier 22.12 floor because ESLint 10 requires it on the Node 22 line. Node's major stays 22 and npm stays 10. New quality packages are pinned exactly and stored in `package-lock.json`; all existing direct frontend package versions remain unchanged.

## Formatting scope

`prettier.config.js` uses four spaces, single quotes, semicolons, trailing commas, a 100-column target and LF line endings. JSON files use two spaces to match `.editorconfig`. Prettier formats TS/TSX declarations, CSS, root JavaScript/TypeScript configuration, `package.json`, `tsconfig.json`, and `scripts/**/*.mjs`. Existing JSX/configuration layout is normalized without changing UI behavior.

`.prettierignore` excludes dependencies, compiled assets, runtime files, secrets, generated lockfiles, PHP/Blade, infrastructure YAML and planning Markdown. Package managers own the lockfiles; PHP-CS-Fixer owns PHP. Prettier does not sort Tailwind classes or add a Blade formatter. `eslint-config-prettier` disables conflicting lint style rules; formatting runs as its own explicit command rather than an ESLint plugin.

## TypeScript options added in M0.05

These fields extend the [bootstrap configuration reference](BOOTSTRAP.md); JSON itself does not support explanatory comments.

| `compilerOptions` field | Effect |
|---|---|
| `noUnusedLocals` | Reject unused local declarations and imports. |
| `noUnusedParameters` | Reject unused parameters; TypeScript's explicit underscore convention remains available. |
| `noImplicitReturns` | Require consistent return paths where a function returns a value. |
| `noFallthroughCasesInSwitch` | Reject unintentional fallthrough between nonempty switch cases. |
| `exactOptionalPropertyTypes` | Distinguish an omitted optional property from an explicitly assigned `undefined`. |
| `verbatimModuleSyntax` | Preserve module syntax and require explicit type-only imports where appropriate. |

The existing `strict`, `noUncheckedIndexedAccess`, isolated-module checking and `noEmit` settings remain enabled. `vite-env.d.ts` now imports `vite/client` declarations instead of using a triple-slash reference, matching the lint policy without changing provided Vite types.

## Automated quality-tool tests

`scripts/quality.test.mjs` uses Node's built-in test runner and the **real checked-in ESLint, TypeScript and Prettier configurations**, without introducing a UI test framework early. Five checks verify:

1. A valid React counter passes typed lint and strict compilation.
2. Conditional hook calls and unhandled promises produce their expected independent lint diagnostics.
3. Explicit `undefined` assigned to an exact optional property fails compilation.
4. Formatting drift is detected and formatted output passes the same check while keeping its documentation.
5. Generated browser bundles are excluded from linting.

Typed linting needs a real project file. Tests create unique `resources/js/quality-probe-*` directories and always remove their own fixtures in `finally`; no existing source file is overwritten. The compiler fixture inherits the root `tsconfig.json` and narrows only its input list. Child compilers have a 30-second failure timeout and emit no assets. Run the suite serially through the provided command; an abrupt process kill can leave a temporary directory that should be removed before continuing development. Such directories are ignored by Git to prevent accidental commits. No Git command, external service, browser or real-time sleep is used by these tests.

These are quality-tool regression checks, not React interaction coverage. M0.06 still introduces the frontend interaction suite and dedicated PostgreSQL test database.

## Rector evaluation

Rector is useful for a **specific, reviewable refactoring or compatibility migration**. Its [dry-run mode](https://getrector.com/documentation/cli-options) reports proposed changes without rewriting files. For a future use, pin a compatible release, explicitly select the target PHP 8.4 baseline, restrict paths and rules to the intended change, inspect the dry-run output, apply the agreed changes, then run PHP-CS-Fixer, Larastan and relevant Pest tests. The user retains ownership of all Git operations.

Decision for M0.05: **evaluated, not installed**. Signalbox already targets PHP 8.4 and Laravel 13; this task has no concrete legacy syntax migration or repeated semantic refactoring to automate. Adding broad prepared rule sets now would create unrelated changes and another dependency without a defined acceptance criterion. Rector does not replace formatting or static analysis. Revisit when an actual upgrade/refactoring has a bounded objective. No Rector execution or Rector/Laravel compatibility test is claimed in this milestone.

## Docker verification

Refresh the separate Linux frontend dependency volume after the lockfile changes:

```sh
docker compose run --rm node npm ci --ignore-scripts --strict-peer-deps
docker compose run --rm node npm run check
docker compose run --rm node npm run build
docker compose exec web composer format:check -- --sequential
docker compose exec web composer analyse -- --debug
docker compose exec -e RUN_DOCKER_TESTS=1 web php vendor/bin/pest
```

`npm ci` also works without `--ignore-scripts`; none of these quality tools requires a lifecycle install script. Host and Linux dependencies remain separate. Do not add Git hooks: Git operations are reserved to the user, and M0.07 will enforce the same non-mutating commands in CI. The unified full-stack verification/test workflow remains M0.06/M0.07; this task only adds the frontend quality command.
