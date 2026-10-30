# Authorization and tenant isolation matrix

M1.05 verifies the existing HTTP surface against the identity rules in [TEAMS.md](TEAMS.md) and tenant boundaries in [TENANCY.md](TENANCY.md). It adds regression coverage, not new product permissions or endpoints. [TASKS.md](TASKS.md) records actual results and limitations.

## Private route contract

Owner scenarios below use the primary owner. Member mutations target another local member; transfers target a different existing local owner. All requests have verified identity and recent password confirmation, so a password redirect cannot conceal a missing authorization check.

| Named route | Method | Owner | Admin | Member | Viewer |
|---|---|---|---|---|---|
| teams.show | GET | 200 | 200 | 200 | 200 |
| teams.select | POST | 302 | 302 | 302 | 302 |
| teams.members.update | PATCH | 302 | 302 | 403 | 403 |
| teams.members.destroy | DELETE | 302 | 302 | 403 | 403 |
| teams.ownership | GET | 200 | 403 | 403 | 403 |
| teams.ownership.store | POST | 302 | 403 | 403 | 403 |
| teams.invitations.store | POST | 302 | 302 | 403 | 403 |
| teams.invitations.resend | POST | 302 | 302 | 403 | 403 |
| teams.invitations.destroy | DELETE | 302 | 302 | 403 | 403 |
| teams.monitors.index | GET | 200 | 200 | 200 | 200 |
| teams.monitors.create | GET | 200 | 200 | 200 | 403 |
| teams.monitors.show | GET | 200 | 200 | 200 | 200 |
| teams.monitors.edit | GET | 200 | 200 | 200 | 403 |
| teams.monitors.store | POST | 302 | 302 | 302 | 403 |
| teams.monitors.update | PATCH (also PUT) | 302 | 302 | 302 | 403 |
| teams.monitors.destroy | DELETE | 302 | 302 | 302 | 403 |
| teams.monitors.pause | POST | 302 | 302 | 302 | 403 |
| teams.monitors.resume | POST | 302 | 302 | 302 | 403 |
| teams.incidents.index | GET | 200 | 200 | 200 | 200 |
| teams.incidents.show | GET | 200 | 200 | 200 | 200 |
| teams.incidents.acknowledge | POST | 302 | 302 | 302 | 403 |
| teams.incidents.updates.store | POST | 302 | 302 | 302 | 403 |
| teams.incidents.updates.publish | POST | 302 | 302 | 302 | 403 |
| teams.incidents.severity | PATCH | 302 | 302 | 302 | 403 |
| teams.incidents.root-cause | PATCH | 302 | 302 | 302 | 403 |
| teams.monitors.maintenance.store | POST | 302 | 302 | 302 | 403 |
| teams.monitors.maintenance.update | PATCH | 302 | 302 | 302 | 403 |
| teams.monitors.maintenance.destroy | DELETE | 302 | 302 | 302 | 403 |
| teams.notifications.index | GET | 200 | 200 | 200 | 200 |
| teams.notification-channels.store | POST | 302 | 302 | 403 | 403 |
| teams.notification-channels.update | PATCH | 302 | 302 | 403 | 403 |
| teams.notification-channels.destroy | DELETE | 302 | 302 | 403 | 403 |
| teams.notification-channels.verification.store | POST | 302 | 302 | 403 | 403 |
| teams.notification-channels.verification.confirm | POST | 302 | 302 | 403 | 403 |
| teams.notification-channels.activation | PATCH | 302 | 302 | 403 | 403 |
| teams.notification-channels.quiet-hours | PATCH | 302 | 302 | 403 | 403 |
| teams.notification-channels.signing-secret | POST | 200 | 200 | 403 | 403 |
| teams.escalation-policies.store | POST | 302 | 302 | 403 | 403 |
| teams.escalation-policies.update | PATCH | 302 | 302 | 403 | 403 |
| teams.escalation-policies.destroy | DELETE | 302 | 302 | 403 | 403 |
| teams.monitors.escalation-policy | PATCH | 302 | 302 | 302 | 403 |

Every route is tested with each actor role in three states: current membership, a disjoint target team and revoked membership with a stale selected team and preloaded membership collection. The last two states must return 404. Separate guest and unverified scenarios must redirect to login and email verification respectively. GET routes retain Laravel's HEAD alias; the inventory checks exact verbs excluding that implicit alias.

Successful requests must produce the expected persistent effect, not merely redirect: selection changes a null preference, role changes persist, membership deletion removes the target, primary ownership changes, issuance creates a local invitation, resend rotates the digest and revocation changes the lifecycle. Read pages assert explicit team/actor projections and only manageable invitations. Foreign aggregate state remains unchanged for both successful and rejected local requests.

Rejected operations compare deterministic database snapshots of all teams, memberships, invitation lifecycles and user selection preferences. The snapshots deliberately use raw test-database reads so a changed ownership field cannot hide behind a scope. Account credentials are excluded. Fake notifications are reset after fixture issuance; rejected operations must send nothing. Responses must not expose the unrelated team's name, member email, invitation email or capability digest. Tenant context must be cleared after private requests.

## Hierarchy, nested IDs and selection

A second matrix crosses all four actor roles and all four target roles for member updates/removals and invitation issuance/resend/revocation. Member updates in this matrix demote the target to viewer, with another owner present; target owners are never the primary owner. A separate 4×4 matrix checks requested promotion/demotion roles from an existing member. Expected permissions are declared in tests rather than computed through production policies.

A primary owner must receive 404 when a member or invitation from another team is placed under an accessible team URL, and when a foreign owner is submitted as the ownership successor. The request uses actual stored identifiers, not guessed IDs. Both aggregates remain unchanged. Forged team/actor fields do not replace the route identity or authenticated actor.

Team listing and account projections are exercised for all four roles, before and after revocation. A stale selection must resolve to null without selecting another team. Account queries containing foreign user/team IDs still return the authenticated account's safe projection. Switching from owner in A to viewer in B must not grant manager privileges in B; explicitly viewing accessible A does not change the saved preference for B.

## Capability and account entrypoints

The inventory explicitly classifies the remaining routes without forcing them into tenant middleware:

| Route | Method | Boundary and coverage |
|---|---|---|
| teams.index | GET | Membership-constrained listing; four roles, revoked membership, guest and verification gates |
| account | GET | Authenticated account projection; foreign input IDs and stale selection |
| account.security | GET | Own account, verified email and recent password; foreign query IDs ignored |
| broadcasting.auth | POST | Verified web session and CSRF; canonical private team/socket input; fresh membership, all four roles; native local Reverb signature |
| invitations.open | GET | Proved capability only; ID/token from different teams rejected |
| invitations.review | GET | Minimal capability-proved preview; no private team access before acceptance |
| invitations.register | GET | Guest with pending capability; registration page tested in M1.03 |
| invitations.register.store | POST | Matching pending capability, no implicit team; positive/invalid/expired cases in M1.03 |
| invitations.switch-account | POST | Authenticated account, including unverified invitee; logout preserves only proved continuation |
| invitations.accept | POST | Verified matching identity and server-owned continuation; posted credentials cannot establish authority |

A valid capability may reveal its intended preview but does not authorize the team directory. Only explicit acceptance grants membership. Posting invitation ID/hash/token fields without opening the bearer link does not manufacture server-side continuation, for guests, unverified or verified accounts. Existing M1.03 tests retain coverage for expiry, revocation, rotation, replay, wrong email, invited registration, account switching through 2FA, primary/last-owner invariants and real PostgreSQL lock contention.

Public authentication/home routes do not expose tenant resources; their request/response and verification boundaries remain covered by AuthenticationSurfaceTest, AuthenticationTest and TwoFactorAuthenticationTest. Package Horizon operator gates remain in ServicesConfigurationTest. M1.04's TenantIsolationTest retains model scope, HTTP binding, queue serialization, worker cleanup and CLI unit-of-work coverage. These are not new API, broadcast or public status-page implementations.

## Keeping the matrix complete

TeamRouteMatrix declares the private routes and account/capability/subscription routes independently of application policies. The inventory assertion compares registered routes with that catalogue and verifies private middleware and verbs. It discovers domain controllers outside the Auth namespace and HomeController, team/invitation URI prefixes, account URIs and any route using EstablishTeamContext. Adding a new domain controller or tenant route therefore requires an explicit catalogue update rather than silently omitting authorization coverage.

Each subsequent milestone must extend the catalogue and datasets for its concrete resources, roles, nested IDs and side effects. API routes must add token abilities alongside membership and policy; search, cache, CLI, jobs and broadcasts require their own actual integration assertions. Public projections need explicit publication tests, not an artificial browser tenant. Document exceptions and the owning milestone instead of excluding a private entrypoint without explanation.

`broadcasting.auth` is classified outside the route-team matrix because the tenant is carried by the validated private channel, not a `{team}` route binding. `BroadcastAuthorizationTest` supplies its actual integration matrix: all roles may receive a grant for a current membership; foreign/revoked/missing teams produce the same 403 rather than the route-team 404. Membership is queried afresh without selecting a team or changing TenantContext. JSON guests/bearer-only actors receive 401, unverified accounts 403, malformed input 422, missing CSRF 419 and incomplete signing credentials 503. Signature verification/tampering tests use the real Reverb verifier in memory. The admission boundary does not evict sockets already subscribed before revocation; see [BROADCASTING.md](BROADCASTING.md#subscription-authorization-m602).

Run targeted and complete checks using the isolated services:

```sh
docker compose run --rm php-test composer test -- --filter=TeamAuthorizationMatrix --fail-on-skipped --fail-on-warning
./scripts/verify.sh
```

No migration, dependency change or application behavior change is required by this task. Tests use synthetic identities, fake email, frozen time and the guarded PostgreSQL/Redis services; they do not use development/customer data or real network delivery.

## M2.04 monitor routes

The five monitor mutation routes from [MONITOR-CRUD.md](MONITOR-CRUD.md) join the private matrix with the same role × membership-state cases, guest/unverified gates and raw snapshots, which now include every `monitors` row. Successful cases assert the persisted effect: creation of a local monitor, rename, deletion, pause, and resume of a monitor paused before the snapshot. A foreign monitor ID under an accessible team URL returns 404 for update, delete, pause and resume. Monitor input rejects unknown fields, so forged `team_id` or actor fields fail validation instead of being ignored; MonitorCrudTest covers that case and direct action calls by viewers or revoked members.

## M2.05 monitor pages and search

The four monitor pages from [MONITOR-UI.md](MONITOR-UI.md) join the private matrix with the same role × membership-state cases and guest/unverified gates; rejected requests keep the raw snapshots unchanged. Successful cases assert the Inertia projection: the list contains only the local monitor, with no configuration on rows and `can.create` false for viewers; the creation form reports local usage; detail and edit pages project the local monitor with role-dependent permissions. Foreign monitor IDs under an accessible team return 404 for show and edit. Search isolation has its own integration assertions in MonitorPagesTest: a same-name foreign monitor is never listed, and the explicit team predicate is checked independently of TeamScope. The store and destroy cases still assert their persisted effects after the redirect change.

## M2.06 per-type permissions and search isolation

The matrix stays the reference for every monitor route × role × membership state. MonitorLifecycleTest adds what the matrix does not vary: viewer denial for each of the five types on the create/edit pages and on every mutation, with the stored rows unchanged, and search isolation when another team's monitors share the searchable TLS host or DNS name. See [MONITOR-UI.md](MONITOR-UI.md#integrated-coverage-m206).

## MA platform admin routes

Admin routes are not tenant routes. TeamAuthorizationMatrixTest's discovery excludes `App\Http\Controllers\Admin`, and PlatformAdminAccessTest keeps its own complete catalogue of `/admin` routes. Every team role (owner, admin, member, viewer) receives 404 on every admin route, with nothing written; guests and unverified users are redirected. See [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md).


## Heartbeat entries (M3.05)

| Entry | Authority | Isolation |
| --- | --- | --- |
| POST /heartbeats | Dedicated Bearer only; browser/Sanctum identity grants nothing | Digest discovery selects owner IDs only, then TenantContext and locked token/type/eligibility revalidation |
| POST/DELETE /teams/{team}/monitors/{monitor}/heartbeat-credential | Verified owner/admin/member through MonitorPolicy update and web CSRF; viewer denied | Existing route-team binding plus fresh locked policy |
| HeartbeatSnapshotReader | Explicit admitted tenant and execution identity | TeamScope plus explicit receipt owner/state/generation/time predicates |

Valid paused/ineligible credentials receive 204 without recording; invalid/revoked credentials receive the same generic 404. New cases are covered by HeartbeatTest and the real HTTP barrier suite. Credentials never enter shared props or the ordinary detail projection. See [HEARTBEATS.md](HEARTBEATS.md).

The central TeamRouteMatrix also explicitly includes both credential mutations and the public ingress catalogue entry. Its parameterized cases cover all managing/viewer roles, foreign or revoked memberships, guest/unverified gates and foreign-child identifiers; aggregate snapshots include heartbeat state and receipts so unauthorized side effects cannot hide outside the original monitor table.

## M4.05 incident and maintenance routes

The ten routes of [INCIDENT-UI.md](INCIDENT-UI.md) join the private matrix with the same role × membership-state cases and guest/unverified gates. The fixture gives each team an open incident with one private entry and an upcoming maintenance window; raw snapshots now include `incidents`, `incident_updates` and `maintenance_windows`, so a rejected request or a foreign aggregate cannot change them unnoticed. Allowed cases assert the persisted effect: list and detail projections, acknowledgement by the actor, status change by a new entry, publication, severity, declared cause, a second window, a rescheduled reason and a deleted upcoming window. Foreign children are placed under the actor's own parents (a foreign incident under the local team, a foreign entry under the local incident, a foreign window under the local monitor) and must return 404 with snapshots unchanged. Scoped bindings additionally reject an entry of another incident or a window of another monitor of the same team, covered by IncidentPagesTest.

## M6.03 partial page recovery

There is no new HTTP route to catalogue. `LivePageRecoveryTest` repeats the four concrete monitor/incident list/detail entrances through real Inertia partial requests, crossing all four roles with current/foreign/revoked membership and preloaded/selected foreign preferences. Existing read permissions remain 200 for members and 404 for foreign/revoked access. Partial reads preserve those gates and public-only connection metadata; filtered pagination is recovered after a missed deletion without returning foreign rows. Frontend tests separately reject cross-team/account responses, fence late reads after a same-component switch/logout, and close the socket when a read denies access. See [REALTIME-CLIENT.md](REALTIME-CLIENT.md).
