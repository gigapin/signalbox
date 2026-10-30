# Private team broadcasts (M6.01–M6.03)

Committed monitoring results and incident transitions now produce minimal private-team updates through the existing Reverb infrastructure. Work state/results are in [TASKS.md](TASKS.md), architectural decision ADR-013 is in [ARCHITECTURE.md](ARCHITECTURE.md), infrastructure configuration is in [SERVICES.md](SERVICES.md).

M6.01 implements producers and delivery; M6.02 adds session-protected private subscription authorization. M6.03 adds Echo/frontend reconciliation and reconnect recovery on the four existing monitor/incident pages; see [REALTIME-CLIENT.md](REALTIME-CLIENT.md) for runtime configuration, lifecycle, bounded event-driven partial reads and tests. Dashboard/charts remain M6.04 and definitive uptime/SLA remains M7.

## Subscription authorization (M6.02)

`POST /broadcasting/auth` (`broadcasting.auth`) uses the normal web middleware, CSRF protection, the explicit `web` guard, verified email and private/no-store responses. Native broadcast routes are not enabled: GET returns 405 and `/broadcasting/user-auth` remains absent. Bearer tokens alone cannot authorize browser subscriptions. Requests expecting JSON receive 401 for guests and 403 for unverified accounts; ordinary web requests retain the existing login/verification redirects.

`AuthorizeBroadcastRequest` accepts only a canonical `private-team.{id}` channel with a positive decimal team identity (up to 19 digits) and a Pusher socket ID containing two numeric parts (up to 20 digits each). Exact channel/socket input bypasses string trimming, so surrounding whitespace and newlines are rejected rather than silently signed. Public, presence, user, suffix and JSONP callback variants fail validation with 422. Out-of-range integer IDs, foreign teams and nonexistent teams receive the same 403 denial without a signature.

`TeamChannel::join()` delegates to the shared `TeamAccess` persisted-membership query. Owner, admin, member and viewer may subscribe to each team they currently belong to. A selected team, operator flag, posted user/team fields or previously loaded relation never grants access. Authorization does not change the selected team or install/change a tenant context. Each subsequent authorization checks membership again, including after revocation.

`BroadcastAuthorizationController` lazily registers only `team.{teamId}` on the explicit native Reverb connection with the `web` guard. This avoids resolving credentials during unrelated application bootstrap and works independently of the publishing driver's null/log setting. Missing/blank application ID, key or secret, or a non-Reverb driver configuration, yields 503. The installed Pusher broadcaster generates the local HMAC over `socket_id:channel_name`; the response contains only `auth: "public-key:signature"`. No network request, presence data, model, secret or notification dispatch is involved. A signature for another socket/channel fails the real Reverb private-channel verifier.

This is an admission/reauthorization boundary. Standard private-channel grants are checked by Reverb when subscribing, not on each delivered message; removing a membership does not forcibly disconnect an already subscribed socket or expire an already issued grant. The endpoint denies new grants after revocation but implements no server-side eviction. M6.03 disposes sockets on page/team/account changes and closes them when recovery reads deny access; channel hardening remains part of M6.05. No claim of immediate server-side revocation for an existing live connection is made.

## Transaction and tenant boundary

`ExecutionResults::record()` dispatches immutable internal `App\Events\Monitoring\CheckCompleted` after writing the terminal result, summary and transactional incident observer effects. It implements `ShouldDispatchAfterCommit`; rollback or a failing observer discards both result and event. This covers genuine observations and missed/cancelled/infrastructure terminal outcomes. The same rounded integer latency is persisted and emitted. Duplicate/stale ledger completions do not produce another terminal result/event.

The existing `IncidentOpened`, `IncidentReopened` and `IncidentResolved` events already implement the same after-commit boundary. Their notification listener remains intact. `PublishTeamUpdates::publish()` is explicitly registered for the four domain events; its method is deliberately not named `handle`, preventing convention-based duplicate listener discovery.

The listener enters the event's `TenantContext`, verifies the monitor and committed result/incident correlation through ordinary TeamScope queries, then restores the caller's context. Result correlation includes execution, monitor, UTC-normalized slot, revision and region. Missing, deleted or foreign correlations publish nothing. Domain events are internal trusted facts, not a public request format. Correlation queries authorize identity, while payload values are frozen at recording time rather than reread from mutable models.

After commit the listener constructs `TeamUpdate`, which retains only scalar whitelisted data and the channel owner. No Eloquent model or domain event object enters the queue frame. It queues Laravel's native `BroadcastEvent` through `BroadcastManager`; the frame's native queue attributes select Redis and `broadcasts`, independently of `QUEUE_CONNECTION=sync`. Reverb I/O occurs in that worker, outside domain transactions and locks. The broadcast job performs no model rehydration or private database reads.

## Wire contract

All frames target exactly one `PrivateChannel('team.{id}')`; its protocol name is `private-team.{id}`. No public or presence channel is emitted. `team_id` is routing metadata and is not duplicated in the wire payload. Short event names are explicit `broadcastAs()` values; Echo listeners use leading-dot names such as `.CheckCompleted`.

Every payload includes integer `schema_version: 1` and `monitor_id`.

| Event | Additional fields | Meaning |
|---|---|---|
| `CheckCompleted` | `execution_id`, `region`, `slot_at`, `monitor_revision`, `kind`, `status`, `response_time_ms`, `checked_at`, `recorded_at` | One terminal regional result, frozen when recorded |
| `IncidentOpened` | `incident_id` | Invalidate the authorized current incident/monitor projection |
| `IncidentReopened` | `incident_id` | Invalidate the current projection; not a replay of a historical status snapshot |
| `IncidentResolved` | `incident_id` | Invalidate the current projection; no timeline, cause text or recipient content |

`region`, `kind` and `status` use the existing enum values. Instants are ISO-8601 UTC with second precision. Latency is an integer number of milliseconds or null when not measured; `checked_at` is null when there was no observation. `recorded_at` is the recording instant, not an invented check time or an exact database commit timestamp. Non-observations retain `status: unknown`; observed failures remain data and do not receive a check retry.

Regional `status` is **not** the monitor's agreed `last_status`: a first regional success or regional disagreement can leave that aggregate unknown. Historical/out-of-order results retain their original slot/revision/region. M6.03 validates/deduplicates check identities and batches authoritative partial page reads instead of assigning regional status directly to the aggregate. Incident frames are invalidations precisely because an older queued transition can arrive after newer state; clients do not infer current incident state solely from the event name. Future chart consumers must preserve these semantics.

Payloads omit monitor names, URLs, headers, request/response bodies, HTTP/error details, TLS/DNS configuration, heartbeat credentials, incident cause notes, timeline text, user details and notification recipients/secrets. `broadcastWith()` always selects the complete payload explicitly; framework reflection never chooses public model properties. The native job adds an internal nullable socket field, which the Reverb broadcaster removes before transmission when unused. The installed Pusher SDK encodes a single destination with its singular `channel` field.

## Delivery and failure policy

| Setting | Value |
|---|---|
| Queue connection / queue | Explicit Redis / `broadcasts` |
| Dedicated Horizon supervisor | `broadcasts`, one process, 128 MB |
| Maximum transport attempts | 3 |
| Retry delays | 1 s, then 5 s |
| Job / worker timeout | 10 s |
| Reverb HTTP connect / total timeout | Existing 3 s / 5 s |
| Redis reservation `retry_after` | Existing 90 s |
| Horizon queue wait alert | 60 s |

Only the UI transport retries. Check retries and durable notification retry budgets are unchanged. Reverb failure is handled by the native bounded broadcast job and never revisits check/incident persistence. A failure to enqueue is caught and logs only fixed text with team/monitor IDs; it cannot turn a persisted check into a failed check job or stop the independent notification listener. With `broadcasting.default` null or the literal `null`, the listener skips publication without contacting Redis. Other configured broadcasters, including local log, use the same queued frame contract.

UI broadcasts are **best-effort**, with no new durable outbox. A crash between domain commit and event publication or an unavailable broker can lose an update; retries or event replays can duplicate it, and queue delays can reorder updates. The database remains authoritative. M6.03 recovers current state after every private subscription/reconnection, browser wake and accepted event without a polling interval. Lost frames on an otherwise connected socket wait for the next recovery trigger. No exactly-once or guaranteed live-delivery claim is made. The existing durable monitoring and notification outboxes retain their separate guarantees.

## Operations and verification

M6.01–M6.02 introduce no migration, dependency, lockfile, environment variable or key changes. M6.03 adds the locked Echo/Pusher browser dependencies and optional public browser coordinates documented in [REALTIME-CLIENT.md](REALTIME-CLIENT.md); install the npm lock and rebuild assets. Existing schema migrations and configured Reverb credentials remain prerequisites. Recreate long-lived workers after adopting M6.01's code/supervisor:

```sh
docker compose up -d --no-deps --force-recreate queue scheduler
```

Verification never restarts development processes or migrates development storage. Tests use `Queue::fake()->serializeAndRestore()`, guarded PostgreSQL and fake Reverb/Guzzle transports. `TeamBroadcastPayloadTest` verifies exact schemas, private channels, native Redis/queue/retry metadata, explicit Redis under a synchronous default, the signed real broadcaster request and the POST-only session authorization surface. `TeamBroadcastTest` covers observed/non-observed outcomes, duplicate completion, observer rollback, real outer commit/rollback on an independent connection using the production transaction manager, incident invalidations, broker/transport failures, independent notification intents, foreign/deleted correlations, context restoration, disabled drivers, regional disagreement, historical revisions and equivalent UTC slot instants.

`BroadcastAuthorizationTest` covers all roles, foreign/revoked/stale membership, multiple accessible teams, context restoration, forged identities, guest/unverified/bearer-only entry, malformed inputs and JSONP, integer overflow, missing credentials, exact signatures, cache headers and real CSRF middleware. Native Reverb private-channel verification uses a real connection with an in-memory socket adapter to prove acceptance and socket/channel/signature tampering rejection without opening a listener.

```sh
docker compose run --rm php-test vendor/bin/pest tests/Feature/TeamBroadcastPayloadTest.php tests/Integration/TeamBroadcastTest.php tests/Integration/BroadcastAuthorizationTest.php --fail-on-skipped --fail-on-warning
./scripts/verify.sh
```

Client/configuration test coverage is in [REALTIME-CLIENT.md](REALTIME-CLIENT.md). Actual counts/results are recorded in TASKS. Remote CI/PHP 8.5, real browser/Echo subscriptions and live Reverb worker delivery are not implied by these tests. M6.04–M6.05 and M7 remain open.
