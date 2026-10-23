# Local Docker environment

M0.03 uses Docker Compose directly, without adding Sail or changing Composer/npm dependencies. This is a development environment, not the M12 production image. All application processes share a PHP 8.4 CLI image and a source bind mount. See [TASKS.md](TASKS.md) for implementation status.

## First setup

Requirements: Docker Engine/Desktop running and Docker Compose v2 or later. Host PHP, Composer and Node are not required. From the repository root:

```sh
cp .env.example .env
```

Only copy the template when `.env` does not already exist. Set a private, non-empty `DB_PASSWORD` in `.env` (for example, generate one with `openssl rand -hex 24`). Set `LOCAL_UID` and `LOCAL_GID` to the output of `id -u` and `id -g`, respectively. This keeps bind-mounted files writable on Linux; Docker Desktop also supports the default 1000 values. Never commit `.env`.

```sh
docker compose config --quiet
docker compose build web node
docker compose run --rm --no-deps web composer install
docker compose run --rm --no-deps web php artisan key:generate
docker compose run --rm --no-deps web php artisan config:clear
docker compose run --rm node npm ci
docker compose run --rm node npm run build
docker compose up -d postgres redis
docker compose run --rm web php artisan migrate
docker compose up -d web queue scheduler
```

Generate the key only on first setup. Migration is explicit and targets the local Compose PostgreSQL database. Composer runs without dependencies so a fresh checkout can install packages before starting infrastructure. Application commands require the generated key and installed dependencies. No automatic seed, migration, dependency installation or data deletion occurs at container startup.

Open <http://127.0.0.1:8000>. `APP_PORT` changes the published web port; also update `APP_URL` in `.env` to match. Assets are prebuilt: rerun the Node build after frontend edits. A containerized Vite hot-reload service is not part of this task; the existing host Vite workflow remains available.

## Services and configuration

| Service / path | Responsibility |
|---|---|
| `web` | Laravel local development server, port 8000 inside the container; `/up` liveness check. |
| `queue` | Horizon with separate workers for `default`, `checks:eu`, `checks:us`; one attempt, 30-second timeout, 60-second shutdown grace. Application configuration and operator access are documented in [SERVICES.md](SERVICES.md). |
| `scheduler` | Foreground `schedule:work`; Horizon snapshots every five minutes, with monitor dispatch deferred to M3. |
| `reverb` | Optional `realtime` profile, internal port 8080; explicit local origins and signed backend transport configured in M0.04. Browser UI and private channels follow in M6. |
| `node` | One-off frontend tool, Node 22/npm 10; `tools` profile avoids a permanent idle container. Explicit `compose run node` activates it automatically. |
| `php-test` | One-off isolated backend verification, with dedicated test service dependencies; see [TESTING.md](TESTING.md). |
| `postgres-test` / `redis-test` | Unpublished ephemeral services for integration tests; no application volumes or credentials are reused. |
| `postgres` | PostgreSQL 16 with a named data volume and `pg_isready` health check. |
| `pgadmin` | Optional `dbadmin` profile: pgAdmin 4 web UI for the development database only, published on `127.0.0.1:${PGADMIN_PORT:-5050}`; see [Optional pgAdmin](#optional-pgadmin). |
| `redis` | Redis 7 with append-only persistence and a named data volume; `PING` health check. |
| `docker/php/Dockerfile` | Official PHP image, required extensions, official Composer binary and configurable non-root user. |
| `docker/node/Dockerfile` | Non-root Node runtime with a writable dependency volume separate from host `node_modules`. |
| `docker/php/local.ini` | 512 MB PHP memory limit, UTC runtime timezone and immediate CLI source revalidation. |
| `.dockerignore` | Exclude secrets, Git metadata, dependencies and generated artifacts from image build contexts. |

Compose supplies `DB_HOST=postgres`, `REDIS_HOST=redis`, internal standard ports, Redis sessions/cache and Redis queues. These process environment values override the host-oriented `.env` settings without rewriting them. Do not use cached host configuration inside containers: run `php artisan config:clear` via Compose after switching environments. Host `.env` session/cache/queue defaults remain suitable for infrastructure-free bootstrap.

Only web, optional Reverb and optional pgAdmin publish ports, bound to host loopback. PostgreSQL and Redis are available inside the project network only. Local Redis is unauthenticated and must not be exposed publicly. Database credentials are interpolated from the ignored `.env`; avoid sharing the expanded output of `docker compose config` (use `--quiet`). Existing PostgreSQL volumes retain the original account/password: changing `.env` alone does not update database credentials.

`depends_on: service_healthy` gates application startup on PostgreSQL/Redis health. It does not recover application connections after every runtime infrastructure failure. `/up` is Laravel liveness, not proof of database/worker readiness. See [Docker startup order](https://docs.docker.com/compose/how-tos/startup-order/), [official PHP extension installation](https://hub.docker.com/_/php), and [Laravel Reverb](https://laravel.com/docs/13.x/reverb).

All six external images are pinned to multi-platform registry index digests in Compose/Dockerfiles. This preserves architecture selection on Apple Silicon and amd64. Review and update digest pins explicitly for security updates. Debian package installation is not snapshot-pinned; this does not claim byte-for-byte reproducible image builds. Package versions remain fixed by the committed application lockfiles.

## Verification

```sh
docker compose exec web php -v
docker compose exec web composer check-platform-reqs
docker compose exec web composer format:check -- --sequential
docker compose exec web composer analyse -- --debug
docker compose exec web composer test
docker compose run --rm php-test php vendor/bin/pest --group=docker
docker compose run --rm node npm run build
docker compose ps
docker compose logs --tail=50 web queue scheduler
curl --fail http://127.0.0.1:8000/up
```

The opt-in `docker` Pest group uses real PostgreSQL transactions, Redis cache round trips, competing locks and reserve/delete queue transport behavior. M0.04 added Sanctum bearer-token and Scout query tests; M0.06 now runs all integration cases against dedicated ephemeral services, with migrations managed by RefreshDatabase. See [TESTING.md](TESTING.md). It uses disposable temporary tables and unique Redis keys/queues, and skips during ordinary tests. It does not execute domain jobs or verify distributed scheduling. Ordinary tests continue to use memory cache/session and synchronous jobs.

For the optional Reverb process, set local `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env` before starting it:

```sh
docker compose --profile realtime up -d reverb
docker compose logs --tail=50 reverb
```

`REVERB_APP_ID` identifies the local application and `REVERB_FORWARD_PORT` selects the host port. Broadcasting defaults to the log driver until explicitly selected; [SERVICES.md](SERVICES.md) documents how to enable signed backend publishing. Starting the listener does not create private channels or domain events.

## Optional pgAdmin

pgAdmin was added on 2026-10-23 at the user's request as a development-only service.
- **Startup:** it belongs to the `dbadmin` profile, so `docker compose up` never starts it.
- **Network:** its only published port is bound to `127.0.0.1`, unreachable from the network.
- **Database:** it reaches only the development `postgres` service, never `postgres-test`.
- **Image:** `dpage/pgadmin4:9.18.0`, pinned to its multi-platform index digest. On 2026-10-23 the `9.17.0`, `9.18.0` and `latest` tags resolved to the same digest; the running image reports release 9.18.

1. In your `.env`, set `PGADMIN_EMAIL` and `PGADMIN_PASSWORD`. They are the pgAdmin login, not database credentials.
   - Nothing is committed, so the container refuses to start until both are set.
   - pgAdmin validates the address format; reserved domains such as `.test` may be rejected, while `you@example.com` works.
   - `PGADMIN_PORT` changes the host port (default 5050).
2. Start it and open <http://127.0.0.1:5050>:

   ```sh
   docker compose --profile dbadmin up -d pgadmin
   ```
3. Expand **Signalbox → Signalbox development**. When asked, enter the database password, which is `DB_PASSWORD` from `.env`; pgAdmin can remember it in its own volume.
4. Stop it with `docker compose --profile dbadmin stop pgadmin`.

[`docker/pgadmin/servers.json`](../docker/pgadmin/servers.json) pre-registers the server. JSON cannot hold comments, so its fields are listed here:

| Field | Value | Meaning |
|---|---|---|
| `Servers.1.Name` / `Group` | Signalbox development / Signalbox | Labels in the pgAdmin tree |
| `Host` / `Port` | `postgres` / `5432` | Development database through the Compose network |
| `MaintenanceDB` / `Username` | `signalbox` / `signalbox` | Defaults of `DB_DATABASE` / `DB_USERNAME`; edit the server in pgAdmin if you changed them |
| `SSLMode` | `prefer` | The local server has no TLS; pgAdmin falls back to plain TCP inside the private network |

The definition is imported only when the `pgadmin-data` volume is created. pgAdmin settings and any saved password live in that volume; `docker volume rm signalbox_pgadmin-data` resets them, and a full `down --volumes` with the `dbadmin` profile deletes them. The upgrade check is disabled, so the service makes no outbound calls of its own. Writes made in pgAdmin bypass the application's tenant scopes, policies and audit; use it to inspect data, not to change it.

## Daily use and reset

```sh
docker compose up -d web queue scheduler
docker compose restart queue scheduler
docker compose logs -f --tail=50
docker compose --profile realtime --profile testing down
```

Restart long-lived workers after PHP source changes. `down` preserves named volumes. Rebuild `web` after changing the Dockerfile or local user IDs; the other PHP services reuse its image.

For a disposable local database only, `docker compose exec web php artisan migrate:fresh` destroys application tables and recreates the baseline schema. Docker uses a separate `node-modules` volume so Linux dependencies do not overwrite host-native modules. Rebuild the Node image after changing local user IDs; if the volume already exists, its ownership must also match the new IDs.

A complete local reset is more destructive:

```sh
docker compose --profile realtime --profile testing down --volumes
docker compose up -d postgres redis
docker compose run --rm web php artisan migrate
docker compose up -d web queue scheduler
```

`down --volumes` permanently deletes this Compose project's PostgreSQL and Redis volumes, including queued jobs, and the Docker frontend dependency volume. Run `docker compose run --rm node npm ci` again after a full reset. It does not delete host source, `.env`, `vendor` or `node_modules`. No reset command is run automatically.

## M0 final verification evidence

The complete bootstrap passed from a fresh user-created checkout on 2026-10-19, with empty dependency directories and new application/test services and volumes. Use `COMPOSE_PROJECT_NAME=signalbox-m0-clean` in that checkout's local `.env` to override the default project name, and distinct `APP_PORT`/`APP_URL` and `REVERB_FORWARD_PORT` values when running beside development. The verified ports were 18090 and 18091. Generate new local credentials rather than copying development secrets; keep the environment file untracked.

The proof reused image build layers but installed all locked application dependencies from scratch. PHP 8.4.26, Node 22.23.3 and npm 10.9.9 passed bootstrap, migrations, the full verification script and HTTP/process/WebSocket smoke checks. No source, dependency constraint or lockfile change was required. Details, counts, earlier user-confirmed PHP 8.5 CI evidence and limitations are recorded in [TASKS.md](TASKS.md). The runtime smoke checks do not establish browser end-to-end behavior or production readiness.

The isolated environment remains available at `http://127.0.0.1:18090`. Run `docker compose --profile realtime --profile testing down` from `/private/tmp/signalbox-m0-clean` when finished reviewing it; this preserves its named volumes and does not target development services. No volume deletion is needed to complete the proof.

## HTTP server environment preservation

The PHP image starts `artisan serve --no-reload` so its HTTP child inherits Compose overrides such as `DB_HOST=postgres` and `REDIS_HOST=redis`. Without this flag, Laravel filters those variables and the child can reload host-oriented `.env` values such as `DB_HOST=127.0.0.1`, breaking browser requests while Artisan commands still connect successfully. After changing `.env`, restart the web service explicitly: `docker compose restart web`. PHP source files remain available through the existing bind mount. To adopt the corrected image, use `docker compose build web` followed by `docker compose up -d --no-deps web`; no database reset or migration is needed for this fix. A real HTTP regression test exercises the image command against only the isolated test database.
