# Private live pages (M6.03)

The monitor/incident list/detail screens and M6.04's operational dashboard use native Laravel Echo through `useLivePage`. [BROADCASTING.md](BROADCASTING.md) owns the scalar event contract and subscription authorization; this document owns browser lifecycle and recovery. [DASHBOARD.md](DASHBOARD.md) records the delivered UI/latency chart and remaining M6.04 dependency on definitive uptime/SLA from M7. Integrated acceptance/hardening remains M6.05.

## Configuration and installation

Install the updated npm lock with `docker compose run --rm node npm ci --ignore-scripts --strict-peer-deps` and rebuild assets through the normal workflow. Runtime dependencies are Echo **2.6.3** and Pusher JS **8.6.0**. The custom React hook wraps native Echo to own session authorization, disposal and page recovery together; it does not add a global client or replace Inertia 2/React 19. Version/registry checks and the targeted existing test-dependency security fix are recorded in [DEPENDENCIES.md](DEPENDENCIES.md).

`HandleInertiaRequests` shares `realtime: {key, host, port, scheme}` only for verified web actors with the publishing driver set to `reverb` and complete existing server credentials. Otherwise it shares null. The key is public; application ID, secret and internal publishing coordinates are never included. Invalid ports/schemes and an HTTP socket on an HTTPS page disable the client. Incomplete setup leaves the normal pages usable.

| Setting | Meaning/default |
|---|---|
| `BROADCAST_CONNECTION` | Existing publishing selector; `log`/`null` disable the browser connection |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_APP_ID` | Existing matching application credentials; only the public key reaches the browser |
| `REVERB_BROWSER_HOST` | Optional public hostname, without scheme/path; blank/absent uses the page hostname |
| `REVERB_BROWSER_PORT` | Public TCP port; absent uses existing `REVERB_FORWARD_PORT`, then 8080 |
| `REVERB_BROWSER_SCHEME` | `http` by default; set `https` with the public TLS endpoint |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Existing server publishing coordinates; Compose continues to use `reverb:8080` internally |

When configuring a public port explicitly, match the forwarded/proxy port visible to the browser. Existing Reverb allowed-origin rules must include the page hostname. For production configure the public TLS endpoint and normal cache/process reload workflow deliberately; verification never changes `.env`, credentials, development storage or running application processes. There is no migration or new worker queue.

## Socket and authorization lifetime

`teamStream.ts` creates one Pusher client and one native `Echo<'reverb'>` per active page/team/account scope. It subscribes only to `private-team.{routeTeamId}` and binds the four explicit short names with leading dots in Echo. No public/presence/user channel, telemetry, global `window.Echo` or global HTTP interceptor is installed. Native Pusher WebSocket reconnection remains enabled; HTTP fallback transports are disabled.

The custom authorizer reads the **current** XSRF cookie for each grant and sends JSON to the existing POST `/broadcasting/auth`, using same-origin credentials, no-store caching and redirect refusal. A 401/403/419 ends admission; server/transport/invalid-grant failures show a recoverable error. Grant requests have a ten-second deadline. Disposal aborts pending grants, clears deadlines, leaves the channel, removes connection bindings and disconnects the socket. Closed callbacks cannot complete a late grant.

The hook creates a new store whenever the Inertia props, page URL/component, route team or detail monitor changes. An account change/logout therefore also disposes the old scope. React StrictMode setup/cleanup/setup is supported. Old socket generations and old snapshot request identities are fenced before cleanup, including an already resolving JSON body. No async callback updates a later page's store.

## Local state and authoritative recovery

Frames are validated against schema version 1 before invalidation. Check execution IDs are positive integers and have a bounded FIFO duplicate fence of 512 entries; detail pages ignore other monitors, while lists and the dashboard accept team events so counts/list membership can change. M6.04 fixes the earlier mistaken UUID validation and pins the contract through a shared PHP/TypeScript JSON fixture. Incident frames are always invalidations: their names cannot establish current incident state after queue reordering or repeated reopenings.

The local React projection is refreshed through a partial Inertia JSON read of the **exact existing page URL**, including filters and pagination. No new snapshot endpoint or authorization bypass exists. Regional facts are never assigned directly to aggregate `lastStatus`, and no provisional uptime/SLA calculation is introduced. The server's existing regional aggregation, policy and tenant scope remain authoritative.

| Screen | Mutable roots recovered together |
|---|---|
| `Dashboard/Show` | `dashboard` |
| `Monitors/Index` | `monitors`, `usage`, `can` |
| `Monitors/Show` | `monitor`, `recentIncidents`, `maintenance`, `escalation`, `can` |
| `Incidents/Index` | `incidents` |
| `Incidents/Show` | `incident`, `deliveries`, `postableStatuses`, `can` |

Reads also request `team`, `auth` and `realtime` for boundary checks. Component, team and actor email must match; each requested root must be present. Only the mutable whitelist replaces local props. Form drafts, validation errors, filters, browser history and scroll remain untouched. The request sends the current asset version, requires an Inertia response, uses same-origin/no-store/redirect-error and has a ten-second deadline. A version conflict/non-Inertia/invalid/network response retains the last authorized projection with an error and offers retry/reload. A mismatched identity or HTTP 401/403/404/419 closes the socket and asks for a full access check. A null runtime projection closes the client as disabled; online/visibility/manual retry cannot reopen that disabled scope. A new page response is required to adopt enabled configuration again.

Recovery runs after **every successful private subscription**, including first connection and reconnection, even if no frame arrived. Browser online/visible transitions also request recovery. Event bursts are coalesced from the first event for 250 ms, avoiding debounce starvation. Only one read runs at a time; events received during it cause one coalesced follow-up. There is no interval, background polling or infinite retry loop after a failed read. Idle pages send no snapshot traffic. Connection, syncing, disabled, stale/error and denied states are announced accessibly; retry/reload controls support normal keyboard use.

## Limits and verification

This is an event-driven projection with bounded HTTP refreshes, not a complete event replay log. A lost frame while a socket stays connected is repaired by the next accepted event, browser wake or reconnect/manual recovery; no uninterrupted-delivery guarantee is added. Many events can still cause up to roughly four serialized snapshot starts per second per active client when reads are fast. This is a configured bound, not a measured load benchmark. M6.04 can use the same typed boundary for charts without inferring aggregate state from individual regions.

Membership revocation denies new grants and snapshot reads. The client closes an old socket when a recovery read rejects access, and page/account changes close it immediately in that client. Standard private-channel admission still provides **no server-side eviction or immediate per-message revocation** for an already connected socket; the M6.02 protocol limit remains explicit.

`LivePageSession.test.ts` uses a controlled clock and HTTP/socket contracts for ordering, duplicate fences, serialized coalescing, reconnect/wake recovery, timeouts, malformed responses and late/disposed callbacks. `TeamStream.test.ts` exercises **real Echo** on an in-memory Pusher transport, proving the private protocol name, short event bindings, CSRF request options, timeout/denial and native cleanup. `RealtimePages.test.tsx` uses the real Inertia provider and actual monitor table plus a lifecycle probe for local rendering, preserved drafts, same-component team/account changes and logout. `LivePageRecoveryTest` exercises public-only configuration and all four real page entrances on PostgreSQL, across four roles and revoked/foreign membership, plus changed filtered pagination. Test-fixture asset versions match the installed build rather than bypassing Inertia's 409 protection.

Actual results/logs are in [TASKS.md](TASKS.md). A live browser/Reverb listener, remote CI/PHP 8.5 and a load benchmark are not claimed by these simulated transport tests.
