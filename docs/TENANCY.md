# Tenant context and authorization

M1.04 implements ADR-003/004 for the currently available team and invitation surface. It builds on [IDENTITY.md](IDENTITY.md) and [TEAMS.md](TEAMS.md). M1.05 adds the [complete parametrized entrance matrix](AUTHORIZATION-MATRIX.md); resource policies for monitors and later domains are introduced with those resources. Actual verification evidence is recorded in [TASKS.md](TASKS.md).

## Explicit lifetime and authority

`TenantContext` is registered with the container's scoped lifetime. `requireTeamId(): int` throws `MissingTenantContext` when no unit of work is active. `run(int $teamId, Closure $operation)` accepts a positive identifier, returns the callback result and restores the preceding identity in `finally`, including nested operations and failures. There is no process singleton, static tenant identity, first-team fallback or derivation from `auth()` inside the context.

The context carries identity only. The caller must establish authority before private work: HTTP checks fresh membership; invitation acceptance proves a capability and matching verified email; trusted CLI/job orchestration selects the work it owns. Passing a positive team ID to `run` does not authenticate an actor or grant a role. Nested entry is allowed for explicitly authorized orchestration and restores its enclosing context.

`EstablishTeamContext` runs after authentication and before `SubstituteBindings`. It authorizes the numeric `{team}` parameter through `TeamAccess` before entering the remaining binding/controller pipeline. This explicit team can differ from `current_team_id`; the saved preference remains a preference. Team listing, account/authentication, invitation entry and invited registration do not require a selected team. Team selection authorizes its explicit destination. A revoked membership or foreign team produces 404; an insufficient role inside an accessible team produces 403.

## Private model boundary

`BelongsToTeam` installs `TeamScope` on `TeamInvitation` and, from M2.01, `Monitor`. Other tenant-owned resources adopt it in their owning milestone. User, Team and the initial Membership lookup remain outside this scope to avoid circular membership resolution and to support multi-team listing.

Ordinary Eloquent queries receive a qualified `table.team_id = active_team` predicate, including counts, aggregates and query update/delete operations. Missing context fails closed. Model saving assigns new ownership from context and rejects conflicting ownership or reassignment of an existing record. Updating also checks ownership because model increment/decrement bypass the saving event. Model deletion validates both original and current ownership.

Laravel normally bypasses global scopes during `fresh`, `refresh`, `refreshForUpdate` and queue restoration. The trait keeps these queries scoped: foreign reloads return null or raise `ModelNotFoundException`; missing context raises `MissingTenantContext`. A job attempting to rehydrate a tenant model before middleware runs therefore cannot silently load private data.

This is an application boundary, not PostgreSQL row-level security. Bulk inserts/upserts, ownership-changing bulk updates, raw SQL, manual scope removal and quiet model writes are trusted persistence operations, not safe alternatives to domain actions. They must use explicit ownership predicates and preserve immutable `team_id`. There is no general controller-facing scope bypass. Loaded models and collections already contain data; do not retain or serialize them across tenant units of work. Private cache keys and resource lookups in future features must include the team.

## Capability entry and transactions

`InvitationLinks::resolve` is the single capability resolver permitted to remove TeamScope. It looks up one invitation ID, verifies its digest in constant time, rejects rotated/revoked/expired capabilities, and returns null for invalid capabilities. The public entry does not accept a caller-selected tenant. Accepted invitations can still resolve for the existing idempotent acceptance semantics.

`AcceptTeamInvitation` derives context from this proved record, acquires the Team lock, verifies the capability again and locks the matching verified User before changing membership/preference. `IssueTeamInvitation` and revocation establish their explicit team context for direct action calls, then verify membership/role under the Team lock before private queries or writes. Context is restored when a transaction or post-commit mail delivery fails. Registration of an invited identity writes only a global User and still requires later verification and explicit acceptance.

## Policy matrix

Laravel discovers TeamPolicy and TeamInvitationPolicy by convention. TeamAccess supplies the same fresh membership and role hierarchy to policies, domain actions and UI projections. Controller authorization is an early gate; actions retain their checks inside transactions so direct callers and concurrent revocation cannot bypass the domain rules.

| Operation | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| View an accessible team / select it | Yes | Yes | Yes | Yes |
| Manage member/viewer roles and invitations | Yes | Yes | No | No |
| Manage owner/admin roles and invitations | Yes | No | No | No |
| Voluntarily leave | Subject to owner invariants | Yes | Yes | Yes |
| Transfer primary ownership | Primary owner only, with recent password | No | No | No |

Role changes check both existing and requested roles. The last owner and designated primary owner remain protected by the domain actions. Transferring primary ownership requires another existing owner and does not demote the previous owner. Invitation management additionally requires the invitation to belong to the active context. Policy admission never overrides these invariants or the Team-then-User lock order.

## Queue and CLI integration

Tenant jobs implement `TenantJob::tenantId(): int` and return `UseTenantContext` from `middleware()`. Serialize only the team ID and resource identifiers, then load resources with scoped queries inside `handle`. A supplied resource ID from another team must fail lookup. The middleware runs each handler in `TenantContext::run` and restores context on both success and failure. It does not choose retries or notification delivery semantics.

Queue model rehydration precedes job middleware, so tenant Eloquent model properties are excluded from job payloads. Failure callbacks run after middleware unwinds: callbacks that need private persistence must establish their own explicit context and validate references again. No product jobs or CLI commands are introduced just to demonstrate the infrastructure.

CLI/scheduler work must enumerate authorized work through a restricted infrastructure query, then call the same bounded context executor with each explicit team ID. Context does not grant a browser actor's permissions to a worker. API token abilities, public status projections, broadcast authorization, search/cache integration and billing entry points remain in their owning milestones.

## Verification

`TenantContextTest` covers absence, invalid IDs, nested restoration and exceptions without infrastructure. `TenantIsolationTest` uses PostgreSQL for ordinary queries, relation traversal, aggregates, scoped bulk mutations, model writes/deletes/increments, reload/restoration and binding before controller invocation. Its temporary fixture table cannot reference permanent PostgreSQL tables; production invitation foreign keys remain covered by the existing schema tests.

HTTP fixtures test explicit route teams, saved preferences, revoked membership, errors and cleanup. A real Redis transport serializes scalar fixture jobs and one Laravel worker processes A with failure, A with a foreign resource ID and B successfully, without resetting the container between jobs. This tests isolation independently of the worker's normal scoped-container reset. A test-only Artisan command verifies explicit CLI execution. The M1.03 regression suite retains invitation, role, ownership, registration, verification, 2FA and concurrent PostgreSQL lock coverage; assertions outside requests now enter their own explicit tenant context.

Run the isolated checks without touching development data:

```sh
docker compose run --rm php-test composer test -- --filter='TenantContext|TenantIsolation|TeamMembership' --fail-on-skipped
./scripts/verify.sh
```

No schema migration, dependency change or development database reset is needed for M1.04.

## M1.05 authorization matrix

[AUTHORIZATION-MATRIX.md](AUTHORIZATION-MATRIX.md) classifies the current private team, capability and account routes and explains the independent actor/target-role datasets, foreign IDs, stale memberships and side-effect snapshots. M1.04 scope/worker tests remain enabled alongside the matrix. New milestones must extend both HTTP coverage and their actual asynchronous/public boundaries.

## M2.01 real monitor model

[MONITORS.md](MONITORS.md) documents Monitor adopting the shared query/write/reload boundary and Team::monitors() for constrained inverse traversal. Tests cover foreign relation reads and writes, bulk predicates, ownership forgery, reloading and context absence on the actual resource. M2.04 adds the monitor mutation routes; actions lock the Team row and enter the explicit tenant context themselves, and the [authorization matrix](AUTHORIZATION-MATRIX.md) covers the routes ([MONITOR-CRUD.md](MONITOR-CRUD.md)).

## M2.05 search isolation

Scout's database engine builds its query from `Monitor::newQuery()`, so TeamScope applies to search. As ADR-003 requires for search, `MonitorListing` also adds an explicit `team_id` predicate. MonitorPagesTest covers same-name foreign monitors and a listing run in one team's context for another team, which returns nothing. Only plaintext columns are searchable; see [MONITOR-UI.md](MONITOR-UI.md).


## Scheduling and execution outbox (M3.01–M3.02)

`CheckExecution` uses the same fail-closed TeamScope and write guard as Monitor. The scheduling services alone discover cross-team work by selecting identifiers, then re-enter the owning context for private reads/transitions. Composite PostgreSQL ownership prevents an execution from referring to another team's monitor. Delivery envelopes serialize only team/execution IDs; worker middleware restores context and acquisition rechecks identity and eligibility. See [MONITOR-SCHEDULING.md](MONITOR-SCHEDULING.md).


## Heartbeat ingress (M3.05)

The dedicated public Bearer route resolves only state/team/monitor identities by a one-way digest before establishing TenantContext. It then locks Team → Monitor → state and revalidates ownership, credential, type and central eligibility. MonitorHeartbeat uses TeamScope; receipt queries always include explicit owner, state and generation predicates, with composite PostgreSQL foreign keys enforcing ownership. Credential management uses existing route-team binding and MonitorPolicy update, including a fresh check under lock. No public caller-supplied tenant identity is trusted. See [HEARTBEATS.md](HEARTBEATS.md).

## Private broadcast producers (M6.01)

`PublishTeamUpdates` reloads monitor/result/incident correlations under the event's explicit TenantContext and restores the caller's identity on every exit path. `TeamUpdate` retains only selected scalar facts and targets one private owner channel; native queued broadcasts rehydrate no model and perform no tenant database reads. Foreign/deleted correlations publish nothing, and no public/presence channel exists. See [BROADCASTING.md](BROADCASTING.md) for contract and limits.

## Private subscription boundary (M6.02)

`POST /broadcasting/auth` takes its tenant from the canonical private-team channel rather than a route parameter or selected preference. Verified web-session actors in all four roles pass only through a fresh `TeamAccess` membership query; stale loaded relations, foreign IDs and operator flags do not authorize. The callback neither changes selection nor enters a tenant context; an existing caller context is preserved. Foreign, missing, revoked and out-of-range team identities return the same 403. A bearer token alone is insufficient, malformed channel/socket input returns 422, and normal CSRF protection remains enabled. Native signing returns only a socket/channel-bound grant. Revocation blocks later grants; standard private subscriptions do not evict already connected sockets. Details and tests are in [BROADCASTING.md](BROADCASTING.md#subscription-authorization-m602).

## Browser live-page scopes (M6.03)

`useLivePage` and `LivePageSession` own one native Echo socket/store per page URL, team, detail monitor and current Inertia identity. Page/team/account changes and logout dispose the old scope; generation/request fences reject late socket callbacks and HTTP/JSON completions. Auth grants use the current session/CSRF cookie and are aborted on disposal. The browser receives only public connection coordinates, never the publishing secret or internal host.

Recovery uses the existing monitor/incident GET routes with Inertia partial headers and the exact filtered/paginated URL. Existing membership, policies and tenant binding execute normally; partial headers cannot select another tenant. Component/team/actor identity and required roots are checked before replacing local projections. Rejected access or identity changes close the old socket. Local form drafts/errors and the selected-team preference are untouched. Standard private-channel admission still has no server-side eviction guarantee; an already subscribed socket closes in this client when navigation/recovery discovers lost access. Tests and protocol limits are in [REALTIME-CLIENT.md](REALTIME-CLIENT.md).

## Dashboard reads (M6.04)

The new dashboard uses the same verified membership/context middleware and monitor read policy. It never derives the tenant from selection preferences or query fields. Explicit chart IDs must resolve inside both the route team predicate and TeamScope; aggregate readers also apply both constraints independently of HTTP. Monitor rows select only safe operational columns, latency SQL is tenant/monitor/window bounded, and incident summaries omit private notes/timeline. Existing Echo recovery checks the same private route and response identity. Details and numerical SLA dependency: [DASHBOARD.md](DASHBOARD.md).

## Realtime acceptance boundary (M6.05)

Native protocol tests now prove that a private publication cannot reach a public lookalike or another valid private subscription; invalid grants and signed-publish tampering are rejected. Origin/client-event/message-budget policies are exercised by the installed Reverb classes. A committed PostgreSQL flow connects the HTTP membership grant to actual native delivery and dashboard recovery; DOM tests connect native Echo to the application hook/page across duplicate messages and identity changes. Pending authorizations are aborted/fenced on connection transitions so an old socket callback cannot interfere with its replacement. Admission remains distinct from server-side membership eviction, which is not implemented; the negative capability limit is asserted explicitly. See [REALTIME-ACCEPTANCE.md](REALTIME-ACCEPTANCE.md).

## Check rollups (M7.01)

Rollup models use `BelongsToTeam` and fail closed without a context. `BuildCheckRollups` discovers only identities and works inside the owning tenant; a forged team/monitor pair finds nothing. Raw SQL and bulk writes name the team explicitly. See [METRIC-ROLLUPS.md](METRIC-ROLLUPS.md).
