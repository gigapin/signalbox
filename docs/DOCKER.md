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
| `queue` | Redis worker for `default`, `checks:eu`, `checks:us`; one attempt, 30-second timeout, 40-second shutdown grace. Horizon configuration follows in M0.04; regional worker isolation follows in M3. |
| `scheduler` | Foreground `schedule:work`; no monitoring schedule is implemented yet. |
| `reverb` | Optional `realtime` profile, internal port 8080; full credentials, origin policy, broadcasting and UI configuration follow in M0.04/M6. |
| `node` | One-off frontend tool, Node 22/npm 10; `tools` profile avoids a permanent idle container. Explicit `compose run node` activates it automatically. |
| `postgres` | PostgreSQL 16 with a named data volume and `pg_isready` health check. |
| `redis` | Redis 7 with append-only persistence and a named data volume; `PING` health check. |
| `docker/php/Dockerfile` | Official PHP image, required extensions, official Composer binary and configurable non-root user. |
| `docker/node/Dockerfile` | Non-root Node runtime with a writable dependency volume separate from host `node_modules`. |
| `docker/php/local.ini` | 512 MB PHP memory limit, UTC runtime timezone and immediate CLI source revalidation. |
| `.dockerignore` | Exclude secrets, Git metadata, dependencies and generated artifacts from image build contexts. |

Compose supplies `DB_HOST=postgres`, `REDIS_HOST=redis`, internal standard ports, Redis sessions/cache and Redis queues. These process environment values override the host-oriented `.env` settings without rewriting them. Do not use cached host configuration inside containers: run `php artisan config:clear` via Compose after switching environments. Host `.env` session/cache/queue defaults remain suitable for infrastructure-free bootstrap.

Only web and optional Reverb publish ports, bound to host loopback. PostgreSQL and Redis are available inside the project network only. Local Redis is unauthenticated and must not be exposed publicly. Database credentials are interpolated from the ignored `.env`; avoid sharing the expanded output of `docker compose config` (use `--quiet`). Existing PostgreSQL volumes retain the original account/password: changing `.env` alone does not update database credentials.

`depends_on: service_healthy` gates application startup on PostgreSQL/Redis health. It does not recover application connections after every runtime infrastructure failure. `/up` is Laravel liveness, not proof of database/worker readiness. See [Docker startup order](https://docs.docker.com/compose/how-tos/startup-order/), [official PHP extension installation](https://hub.docker.com/_/php), and [Laravel Reverb](https://laravel.com/docs/13.x/reverb).

All five external images are pinned to multi-platform registry index digests in Compose/Dockerfiles. This preserves architecture selection on Apple Silicon and amd64. Review and update digest pins explicitly for security updates. Debian package installation is not snapshot-pinned; this does not claim byte-for-byte reproducible image builds. Package versions remain fixed by the committed application lockfiles.

## Verification

```sh
docker compose exec web php -v
docker compose exec web composer check-platform-reqs
docker compose exec web composer format:check -- --sequential
docker compose exec web composer analyse -- --debug
docker compose exec web composer test
docker compose exec -e RUN_DOCKER_TESTS=1 web php vendor/bin/pest --group=docker
docker compose run --rm node npm run build
docker compose ps
docker compose logs --tail=50 web queue scheduler
curl --fail http://127.0.0.1:8000/up
```

The opt-in `docker` Pest group uses real PostgreSQL transactions, Redis cache round trips, competing locks and reserve/delete queue transport behavior. It uses disposable temporary tables and unique Redis keys/queues, and skips during ordinary tests. It does not execute domain jobs, verify distributed scheduling or implement the separate PostgreSQL test database planned for M0.06. Ordinary tests continue to use memory cache/session and synchronous jobs.

For the optional Reverb process, set local `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env` before starting it:

```sh
docker compose --profile realtime up -d reverb
docker compose logs --tail=50 reverb
```

`REVERB_APP_ID` identifies the local application and `REVERB_FORWARD_PORT` selects the host port. Broadcasting stays on the log driver until M0.04; starting a listener does not enable private channels or application event publishing.

## Daily use and reset

```sh
docker compose up -d web queue scheduler
docker compose restart queue scheduler
docker compose logs -f --tail=50
docker compose --profile realtime down
```

Restart long-lived workers after PHP source changes. `down` preserves named volumes. Rebuild `web` after changing the Dockerfile or local user IDs; the other PHP services reuse its image.

For a disposable local database only, `docker compose exec web php artisan migrate:fresh` destroys application tables and recreates the baseline schema. Docker uses a separate `node-modules` volume so Linux dependencies do not overwrite host-native modules. Rebuild the Node image after changing local user IDs; if the volume already exists, its ownership must also match the new IDs.

A complete local reset is more destructive:

```sh
docker compose --profile realtime down --volumes
docker compose up -d postgres redis
docker compose run --rm web php artisan migrate
docker compose up -d web queue scheduler
```

`down --volumes` permanently deletes this Compose project's PostgreSQL and Redis volumes, including queued jobs, and the Docker frontend dependency volume. Run `docker compose run --rm node npm ci` again after a full reset. It does not delete host source, `.env`, `vendor` or `node_modules`. No reset command is run automatically.
