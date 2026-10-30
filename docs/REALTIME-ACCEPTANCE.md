# Realtime acceptance matrix (M6.05)

This records the concrete evidence for payloads, operational UI recovery, duplicate handling and private-channel boundaries. [BROADCASTING.md](BROADCASTING.md) owns producer/admission contracts, [REALTIME-CLIENT.md](REALTIME-CLIENT.md) owns browser lifecycle, and [DASHBOARD.md](DASHBOARD.md) owns operational/latency projections. Actual commands/results are recorded in [TASKS.md](TASKS.md).

M6.05 covers the implemented realtime behavior. M7.03 later added the numerical availability/SLA values, which close M6.04 and the M6 exit; their rules and evidence are in [METRIC-ROLLUPS.md](METRIC-ROLLUPS.md#sla-service-m703).

## Criterion → evidence

| Criterion | Automated evidence | Boundary proved |
|---|---|---|
| Minimal versioned check/incident frames | `TeamBroadcastPayloadTest`, `TeamPayloadContract.test.ts`, shared `team-broadcast-payloads.json` | Actual PHP producers match JSON accepted by TypeScript; numeric execution IDs, nullable values and explicit names |
| Commit/rollback and domain independence | `TeamBroadcastTest`, `RealtimeDeliveryTest` | No frame before an actual outer commit; rollback discards frames; transport errors do not undo terminal/incident/notification state |
| Domain → native private delivery → authorized projection | `RealtimeDeliveryTest` | Real terminal ledger, incident open/resolve/reopen, scalar native queue frames, native SDK signed publishing, real Reverb controller/channel delivery and HTTP dashboard recovery |
| Duplicate work and UI replay | `RealtimeDeliveryTest`, `LivePageSession.test.ts`, `RealtimeAcceptance.test.tsx` | One result/incident effect per terminal owner; bounded check deduplication and authoritative replacement prevent duplicate rows/samples |
| Regional disagreement and out-of-order facts | `TeamBroadcastTest`, `RealtimeDeliveryTest`, `RealtimeAcceptance.test.tsx` | Up/down disagreement remains aggregate unknown; old incident names invalidate rather than overwrite current state |
| Actual Echo adapter → hook → DOM | `RealtimeAcceptance.test.tsx` | Native Echo channel naming/EventFormatter and production authorizer/store/page work together; only the Pusher transport and HTTP replies are doubled |
| Missed/cancelled/infrastructure facts | `TeamBroadcastTest`, `RealtimeAcceptance.test.tsx` | Null observation fields remain valid invalidations; no regional status or fabricated latency is assigned directly |
| Batching and no periodic polling | `LivePageSession.test.ts`, `RealtimeAcceptance.test.tsx` | 250 ms first-event coalescing, serialized reads, one pending follow-up and no idle snapshot traffic |
| Reconnect and current session cookie | `TeamStream.test.ts`, `RealtimeAcceptance.test.tsx` | Replacement socket receives a new grant with the current XSRF cookie, then recovers without needing an event |
| Pending authorization race | `TeamStream.test.ts` regression cases | Old requests are aborted; late response headers, JSON success and JSON failure cannot complete an old callback or poison the replacement subscription |
| Scope disposal and lost access | `RealtimePages.test.tsx`, `RealtimeAcceptance.test.tsx` | Team/account/logout changes dispose the old socket; rejected auth prevents snapshot reads, rejected recovery closes the current client, old callbacks/wake cannot revive it |
| Current membership, roles and CSRF | `BroadcastAuthorizationTest`, `TeamAuthorizationMatrixTest`, `RealtimeDeliveryTest` | All four roles read only current teams; stale relations/preferences/flags/bearer-only identities do not grant browser authority; real CSRF middleware is exercised |
| Private destination separation | `ReverbProtocolSecurityTest` | Native signed publication reaches the subscribed private channel only; another valid private channel and a public lookalike receive nothing; repeat subscription does not duplicate channel membership |
| Native publisher integrity/freshness | `ReverbProtocolSecurityTest` | Installed Reverb rejects changed bodies/destinations, missing signatures and a correctly re-signed expired timestamp before delivery |
| Native browser restrictions | `ReverbProtocolSecurityTest` | Origin allowlist accepts localhost/127.0.0.1 and refuses foreign/lookalike/opaque/empty origins; private admission requires a grant; browser client events are refused even for channel members |
| Native message budget | `ReverbProtocolSecurityTest` | The configured 60-message budget is enforced and the next message terminates the transport, without sleeping |
| Configuration/payload secrecy and operational graphs | `LivePageRecoveryTest`, `DashboardTest`, `RealtimeDeliveryTest`, `Dashboard.test.tsx` | No private network configuration in frame/dashboard projections; bounded lists/latency, real gaps/zero values, accessible region controls and explicit unavailable values |

## What runs for real

`RealtimeDeliveryTest` uses `CommittedTeamFixture` on a second connection copied from the **guarded test PostgreSQL** configuration. The production `DatabaseTransactionsManager` tracks that independent connection; it is not RefreshDatabase's outer test transaction. Terminal writes and incident observers execute inside a real outer transaction, which is asserted not to enqueue UI frames until commit. Fixture cleanup runs after the case and does not access development storage.

The accepted owner's HTTP request goes through `/broadcasting/auth`; its signature is consumed by native Reverb `EventHandler`/private channels on a real `Reverb\Connection`. Laravel's real serialized `BroadcastEvent` job is handled by the installed broadcaster/Pusher SDK. Guzzle captures its signed PSR request instead of opening a socket; that exact request is rewound and fed into native Reverb `EventsController`, which validates HMAC, body and timestamp and dispatches through native in-memory channel storage. `RecordingReverbSocket` records only outgoing text frames. The HTTP response transport is an unused double. None of these tests starts Redis workers or a Reverb listener.

`ReverbProtocolSecurityTest` independently drives the actual native handshake, grant verifier, publisher controller, dispatcher, client-event policy and limiter. The valid publish/subscription proves the happy path, while tampering and destination tests prove the negative boundaries. Handcrafted subscription signatures in this protocol-only suite are not treated as membership proof; the HTTP membership boundary is exercised separately and in `RealtimeDeliveryTest`.

`RealtimeAcceptance.test.tsx` mounts the real Inertia provider, `Dashboard/Show`, `useLivePage`, `LivePageSession`, application authorizer and **native Echo**. `MemoryPusher` replaces Pusher's socket/authorization API, with separate clients, connection emitters and exact channel registries. HTTP grants and dashboard replies are synthetic, controlled responses. The helper does not claim to verify cryptography or run Pusher's network reconnection/JSON parser; the PHP protocol tests verify signatures, and the test explicitly initiates replacement connections. This is integrated in-process acceptance, not a live browser end-to-end test.

The existing dashboard data was extracted into a documented test-only `dashboardFixture.ts` so the earlier page tests and the native-Echo cases share the same public projection. It is never imported by production code. Form/navigation tests allow Inertia's final one-off Head update to flush after unmount before asserting no persistent timers/traffic.

## Demonstrated fix

The initial pending-grant regression failed: changing the connection to disconnected did **not** abort the old HTTP grant. Such a response is bound to its original socket ID; completing its Pusher callback later could interfere with the replacement subscription.

`teamStream.ts` now advances an authorization generation on each native connection transition and disposal, aborts pending grants, clears their ten-second deadlines, and fences both response and JSON completion against the captured generation. Superseded callbacks finish silently. Errors/timeouts in the current generation still produce the existing recoverable feedback. Positive new-socket grants, current-cookie signing and current-generation timeout behavior remain tested. No server route, payload schema, queue, configuration or dependency changes.

## Limits retained explicitly

- UI frames are best-effort. Transport retries/replays may duplicate or reorder messages; there is no UI outbox or exactly-once guarantee. Old check identities beyond the bounded deduplication cache can cause another safe authoritative read.
- Private grants are admission capabilities, checked when subscribing. Revocation denies new grants/reads; the client closes when navigation/recovery discovers lost access. **No server-side eviction or immediate per-message membership revalidation** is added. `RealtimeDeliveryTest` explicitly confirms that a previously subscribed native connection still exists after revocation, avoiding a false live-revocation claim.
- Public channels can exist in Reverb, but the product publisher emits only private destinations. Origin checks are browser defenses and do not replace signed application publishing or the HTTP membership gate.
- Snapshots use existing read-committed queries and are reconciled by later triggers; they are not one transactional database watermark. The current no-polling/bounded-read cost remains documented in REALTIME-CLIENT.
- A real browser, live Reverb/Pusher network reconnection, queue-worker delivery, remote PHP 8.5 CI and a load benchmark are not executed by these tests. The normal gate runs PHP 8.4 and controlled transports; live deployment/benchmark evidence remains separate.

## Reproduce

```sh
docker compose run --rm php-test vendor/bin/pest tests/Feature/ReverbProtocolSecurityTest.php tests/Integration/RealtimeDeliveryTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npm test -- tests/frontend/RealtimeAcceptance.test.tsx tests/frontend/TeamStream.test.ts tests/frontend/Dashboard.test.tsx
./scripts/verify.sh
```

No migration, package installation, environment/key change or development-process restart is required for M6.05. The client lifecycle fix requires the normal frontend asset rebuild when adopting it.
