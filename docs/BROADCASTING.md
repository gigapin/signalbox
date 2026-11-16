# Private team broadcasts (M6.01–M6.05)

Committed monitoring results and incident transitions now produce minimal private-team updates through the existing Reverb infrastructure. Work state/results are in [TASKS.md](TASKS.md), architectural decision ADR-013 is in [ARCHITECTURE.md](ARCHITECTURE.md), infrastructure configuration is in [SERVICES.md](SERVICES.md).

M6.01 implements producers and delivery; M6.02 adds session-protected private subscription authorization. M6.03 adds Echo/frontend reconciliation and reconnect recovery; M6.04 extends it to the operational dashboard and measured regional latency chart. [REALTIME-CLIENT.md](REALTIME-CLIENT.md) documents lifecycle and bounded recovery; [DASHBOARD.md](DASHBOARD.md) records the dashboard's availability values.

## Subscription authorization (M6.02)

`POST /broadcasting/auth` (`broadcasting.auth`) uses the normal web middleware, CSRF protection, the explicit `web` guard, verified email and private/no-store responses. Native broadcast routes are not enabled: GET returns 405 and `/broadcasting/user-auth` remains absent. Bearer tokens alone cannot authorize browser subscriptions. Requests expecting JSON receive 401 for guests and 403 for unverified accounts; ordinary web requests retain the existing login/verification redirects.

`AuthorizeBroadcastRequest` accepts only a canonical `private-team.{id}` channel with a positive decimal team identity (up to 19 digits) and a Pusher socket ID containing two numeric parts (up to 20 digits each). Exact channel/socket input bypasses string trimming, so surrounding whitespace and newlines are rejected rather than silently signed. Public, presence, user, suffix and JSONP callback variants fail validation with 422. Out-of-range integer IDs, foreign teams and nonexistent teams receive the same 403 denial without a signature.

`TeamChannel::join()` delegates to the shared `TeamAccess` persisted-membership query. Owner, admin, member and viewer may subscribe to each team they currently belong to. A selected team, operator flag, posted user/team fields or previously loaded relation never grants access. Authorization does not change the selected team or install/change a tenant context. Each subsequent authorization checks membership again, including after revocation.

`BroadcastAuthorizationController` lazily registers only `team.{teamId}` on the explicit native Reverb connection with the `web` guard. This avoids resolving credentials during unrelated application bootstrap and works independently of the publishing driver's null/log setting. Missing/blank application ID, key or secret, or a non-Reverb driver configuration, yields 503. After the native membership gate, the installed Pusher SDK signs `socket_id:channel_name:channel_data`. The response contains `auth: "public-key:signature"` and the exact `channel_data` JSON string containing only the session actor's integer `user_id`. The client passes both values unchanged to Pusher. No network request, presence list, model, email, secret or notification dispatch is involved. Another socket/channel/actor or modified JSON fails native HMAC verification.

SEC.08 adds the server delivery boundary below. The earlier admission-only limitation is superseded for Signalbox private team channels.

## Current membership on delivery (SEC.08)

`TeamChannelManager` is the singleton Reverb channel-manager adapter registered by the application. Reverb v1.12.0's actual startup factory uses `singletonIf`, so it preserves this binding. Only canonical `private-team.{positive PHP int}` channels are replaced with `TeamPrivateChannel`; native storage, application isolation, origins, message limits, signed publication and other channel types retain the upstream implementation. The upstream registry PHPDoc has an extra array level; `stubs/reverb.stub` corrects its static type only, without a dependency/vendor change.

The channel first runs native private HMAC verification, then requires signed JSON with exactly one positive integer `user_id` and checks current verified membership. Missing/legacy actor-less grants, malformed metadata, foreign members and grants issued before membership removal cannot subscribe. The identity cannot come from browser-supplied `user_id`, selection preferences or cached relations. The signed metadata is private subscription state; it is not included in team broadcasts or a presence list.

Before each publication, including direct `broadcastToAll`, internal and sender-excluded paths, the channel takes `FOR SHARE NOWAIT` on the explicit team and reads verified membership for only the connected signed actor IDs. Missing/revoked/unverified members are closed through native `Server::close`, removing all subscriptions on that socket before any payload enters its transport. Other authorized sockets keep receiving. A shared socket for another accessible team is also closed and must reconnect/re-authorize normally. No browser timer, polling, HTTP eviction request or retryable revocation job is required. An idle revoked socket may remain open until the next publication; it receives no later private frame from that team.

`ManageTeamMembers::remove()` already takes the exclusive team lock. The shared publication lock remains held until frames are handed to the nonblocking socket transport: a removal either commits first and is observed, or waits for an already-authorized publication to finish. Frames handed to a transport before revocation can still arrive later; delivered data cannot be recalled. Lock contention raises PostgreSQL `55P03` immediately, and any failed membership query raises before payload delivery; native publication fails so the existing broadcaster transport retry can apply. No failure is interpreted as authorization, no default membership is used, and rolled-back removals do not evict members. The database is authoritative on every publication, without a process cache.

Cost: a team-row lock query and one bounded membership query per team frame with subscribers, synchronously in Reverb's event loop. NOWAIT bounds the aggregate lock wait; this does not bound database connection/network latency. No load benchmark or multi-node production rollout is claimed. The active configuration still runs one listener with scaling disabled; additional nodes must all run the same adapter and use the same authoritative PostgreSQL database.

The installed [private verifier](https://github.com/laravel/reverb/blob/v1.12.0/src/Protocols/Pusher/Channels/Concerns/InteractsWithPrivateChannels.php) signs custom data, and the [user termination controller](https://github.com/laravel/reverb/blob/v1.12.0/src/Protocols/Pusher/Http/Controllers/UsersTerminateController.php) matches `user_id` stored in channel data. Per-publication reauthorization was chosen to avoid an external termination request failing while leaving an old subscription authorized.

Adoption: deploy PHP and rebuilt browser assets together, reload application/worker code, and recreate the Reverb service (`docker compose up -d --no-deps --force-recreate reverb`) so existing anonymous subscriptions are discarded. Fully reload old browser pages; old assets discard `channel_data` and cannot subscribe to the hardened server. Until Reverb is restarted, a long-lived old process retains the old admission-only behavior. No migration, package, environment variable or key change. Verification does not restart development services.

`ReverbMembershipTest` exercises the production binding through the real startup factory, native HTTP grants/HMAC, real channel delivery, all roles and self-departure, multiple sockets, tenant isolation, metadata tampering, role changes, rollback and PostgreSQL two-connection lock contention. `RealtimeDeliveryTest` adds committed domain transitions followed by actual non-delivery after revocation. The infrastructure-free `ReverbProtocolSecurityTest` deliberately isolates upstream native channels; it proves native protocol rules, while database-backed acceptance proves the application adapter.

## Transaction and tenant boundary

`ExecutionResults::record()` dispatches immutable internal `App\Events\Monitoring\CheckCompleted` after writing the terminal result, summary and transactional incident observer effects. It implements `ShouldDispatchAfterCommit`; rollback or a failing observer discards both result and event. This covers genuine observations and missed/cancelled/infrastructure terminal outcomes. The same rounded integer latency is persisted and emitted. Duplicate/stale ledger completions do not produce another terminal result/event.

The existing `IncidentOpened`, `IncidentReopened` and `IncidentResolved` events already implement the same after-commit boundary. Their notification listener remains intact. `PublishTeamUpdates::publish()` is explicitly registered for the four domain events; its method is deliberately not named `handle`, preventing convention-based duplicate listener discovery.

The listener enters the event's `TenantContext`, verifies the monitor and committed result/incident correlation through ordinary TeamScope queries, then restores the caller's context. Result correlation includes execution, monitor, UTC-normalized slot, revision and region. Missing, deleted or foreign correlations publish nothing. Domain events are internal trusted facts, not a public request format. Correlation queries authorize identity, while payload values are frozen at recording time rather than reread from mutable models.

After commit the listener constructs `TeamUpdate`, which retains only scalar whitelisted data and the channel owner. No Eloquent model or domain event object enters the queue frame. It queues Laravel's native `BroadcastEvent` through `BroadcastManager`; the frame's native queue attributes select Redis and `broadcasts`, independently of `QUEUE_CONNECTION=sync`. Reverb I/O occurs in that worker, outside domain transactions and locks. The broadcast job performs no model rehydration or private database reads.

## Wire contract

All frames target exactly one `PrivateChannel('team.{id}')`; its protocol name is `private-team.{id}`. No public or presence channel is emitted. `team_id` is routing metadata and is not duplicated in the wire payload. Short event names are explicit `broadcastAs()` values; Echo listeners use leading-dot names such as `.CheckCompleted`.

Every payload includes integer `schema_version: 1` and `monitor_id`.

`CheckCompleted.execution_id` is a positive integer ledger identity. M6.04 corrects the client's earlier UUID-only validation; `tests/Fixtures/team-broadcast-payloads.json` now pins real PHP producer output against TypeScript validation. The backend schema and version remain unchanged.

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

Client/configuration behavior is in [REALTIME-CLIENT.md](REALTIME-CLIENT.md); M6.05's criterion-to-evidence matrix and native protocol/DOM acceptance are in [REALTIME-ACCEPTANCE.md](REALTIME-ACCEPTANCE.md). Actual counts/results are recorded in TASKS. Remote CI/PHP 8.5, real browser/Echo subscriptions and live Reverb worker delivery are not implied by these tests. M6.04's numerical availability criterion and final M6 exit still depend on M7.
