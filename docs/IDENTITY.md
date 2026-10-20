# Identity and team data foundation

M1.01 introduces persisted identity and team relationships. See [TASKS.md](TASKS.md) for progress and [ARCHITECTURE.md](ARCHITECTURE.md) for ADR-003, ADR-004, ADR-010 and the ownership clarification below. No authentication UI, invitation endpoint, tenant context, TeamScope or role policy is implemented by this task.

## Storage and ownership

Incremental migrations add `teams`, `team_user` and nullable `users.current_team_id`; existing users and framework migrations are preserved. No existing identity receives a manufactured team. Rollback removes the preference before memberships and teams.

`Team` stores name, globally unique lowercase slug, primary `owner_id`, base `Plan`, IANA timezone and immutable trial timestamps. Timezone defaults to `UTC`; timestamp-with-time-zone columns represent absolute instants, and application creation uses UTC. `Plan` persists `free`, `pro` or `business`; a PostgreSQL check rejects other values. This stored base plan does not resolve a paid or trial entitlement.

The initial trial starts at team creation and ends exactly 14 UTC days later. Both `trial_started_at` and exclusive `trial_ends_at` are retained after expiration, recording that the trial has already been granted. Creation does not renew an existing team's trial. Entitlement resolution, expiration processing and Stripe integration remain future work in M2/M9.

`Membership` is the typed pivot for `team_user`, with a composite primary key `(team_id, user_id)`, user lookup index, timestamps and `TeamRole` enum. A PostgreSQL check restricts roles to `owner`, `admin`, `member` and `viewer`. One user can belong to several teams with different roles. **Multiple owners are permitted** by the user's M1.01 decision; `owner_id` identifies the designated primary owner and production creation also gives that identity an owner membership.

Deleting a team removes its memberships and nulls any current-team preferences. Deleting an ordinary member removes their memberships. A primary owner cannot be deleted while referenced by a team. Last-owner protection and ownership transfer belong to M1.03; direct pivot writes are trusted persistence operations and do not enforce those future domain transitions.

## Domain interface and current selection

`App\Actions\Identity\CreateTeam::__invoke(User $owner, string $name, string $slug, string $timezone = 'UTC'): Team` validates trimmed, nonempty names up to 255 characters, lowercase alphanumeric slugs separated by single hyphens up to 255 characters, and IANA timezones. Invalid input raises `ValidationException`; duplicate slugs fail through the database unique constraint rather than silently receiving a different slug.

The action locks the owner's row inside a transaction, then creates the team, owner membership and initial preference together. It preserves any existing non-null preference. Reading that preference under the row lock serializes concurrent creations even when the caller has a stale User model. Errors roll back the complete aggregate. The caller's User instance is not refreshed automatically; retrieve persisted state through a fresh query or the resolver. The action is independent of HTTP/session transport and does not authorize the requesting actor; future entrypoints must apply their policies.

`User::teams()` and `Team::users()` expose membership-constrained many-to-many relations with typed `Membership` pivots. `Team::owner()` and `User::ownedTeams()` identify primary ownership. `Membership::team()` and `Membership::user()` expose its two identities.

`User::resolveCurrentTeam(): ?Team` reads the persisted preference afresh and queries through current membership. It returns null when the preference is absent, deleted or no longer accessible, without falling back to the first team. Loaded relationship caches are not trusted. The raw `current_team_id` remains a preference, never a permission or tenant context. The resolver does not lock membership against subsequent revocation; protected operations still require the request context and policy checks introduced in M1.04.

`current_team_id`, ownership, plan and trial fields are excluded from ordinary mass assignment. Trusted orchestration assigns these explicitly. No global scope is applied to User or initial membership resolution, in accordance with ADR-003.

## Factories and explicit synthetic seeding

`UserFactory` continues to create identities without implicit teams. `TeamFactory` creates the designated owner's membership after persistence and supports `free()`, `pro()` and `business()` states. These are base-plan fixtures, not simulated subscriptions. `MembershipFactory` creates an additional participant and supports all four role states; `owner()` adds an owner without replacing `owner_id`. Factory `make()` does not persist membership side effects.

After applying migrations to a configured local database, explicitly seed with:

```sh
php artisan db:seed
```

`DatabaseSeeder` delegates to `IdentitySeeder`, which refuses environments other than `local` and `testing`, including invocation with Artisan `--force`. It creates four synthetic identities (`owner`, `admin`, `member`, `viewer` at `signalbox.test`) and two teams (`synthetic-alpha`, `synthetic-beta`). Each identity belongs to both teams, demonstrating shared membership with all four roles. Fixture passwords are the public, non-production value `password`; they are hashed by User. No login route is introduced.

Seeding is transactional and repeatable: existing fixture credentials, trial history and selection are preserved; missing memberships are restored and fixture roles synchronized. A matching team slug owned by a different primary owner is rejected instead of reassigned. Reruns may update membership timestamps. No production account, notification, payment or external network request is created.

## Verification

`tests/Integration/TeamIdentityTest.php` exercises actual PostgreSQL constraints, enum casts, creation, validation, rollback, current-team isolation and revocation, multiple owners, deletion, trial duration across the European DST boundary, factory states, repeat seeding and the production guard. It also reverses/reapplies only the M1 migrations while preserving existing baseline identities.

The contention test opens a second connection to the guarded test database. A creation kept uncommitted holds the owner lock; another creation encounters PostgreSQL `55P03` under a 100 ms lock timeout. This verifies real inter-session contention without claiming a parallel-safe full test suite. The separately committed synthetic actor is deleted in cleanup; application data and services are not used.

Use the isolated test lifecycle from [TESTING.md](TESTING.md):

```sh
docker compose run --rm php-test php vendor/bin/pest --filter=TeamIdentity --fail-on-skipped
./scripts/verify.sh
```

M1.01 introduces no public HTTP/TypeScript API and does not establish complete tenant authorization. M1.02–M1.05 remain open.

## M1.02 follow-up

[AUTHENTICATION.md](AUTHENTICATION.md) now documents the implemented authentication UI, email verification and optional 2FA. The M1.01-only statements above describe that task’s historical boundary. User now normalizes assigned emails, implements mandatory verification and hides Fortify-encrypted authenticator fields. Registration uses the existing CreateTeam action inside an outer transaction, then notifies after commit; it does not introduce general team creation/switching or tenant authorization. M1.03–M1.05 remain open.
