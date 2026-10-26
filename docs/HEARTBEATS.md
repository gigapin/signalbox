# Heartbeat reception and evaluation (M3.05)

Heartbeat monitors receive calls from an external job instead of contacting that job. M3.05 implements the dedicated ingress, credential management, tenant-owned receipt history and pure missed-call evaluation. It does not bind the application consumer, enable scheduling, persist CheckResult, update monitor observation summaries or open incidents. Those integrations remain M3.06–M3.07 and M4.

## HTTP interfaces and credentials

| Interface | Authorization and response |
| --- | --- |
| `POST /heartbeats` | Dedicated `Authorization: Bearer <token>` only. Valid credentials return empty 204; absent, malformed, unknown or revoked credentials return the same generic 404. |
| `POST /teams/{team}/monitors/{monitor}/heartbeat-credential` | Verified owner/admin/member with the existing monitor update policy and web CSRF. Generate or rotate; return `{token, endpoint}` once as private, no-store JSON. |
| `DELETE /teams/{team}/monitors/{monitor}/heartbeat-credential` | Same management authorization and CSRF. Revoke immediately; return empty 204. |

Tokens contain 256 random bits encoded as 64 lowercase hexadecimal characters. Only the SHA-256 digest is stored, under a unique index. A cryptographic random token does not require password-style slow hashing. There is no endpoint for recovering or showing an existing token. Issuance and rotation leave the rolling heartbeat deadline unchanged, so they cannot repair an overdue monitor. Revocation also leaves timing unchanged.

Public ingress is registered outside the web/session/Inertia/CSRF middleware stack. Browser login, Sanctum tokens, URL/query secrets and payload ownership fields do not authorize it. No cookies are issued. Request payload and caller-supplied timestamps are ignored; the endpoint never returns team, monitor, configuration or plan details. Unsupported methods receive the framework's ordinary 405 response. Public 204/404/429 results have `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`.

`config/heartbeat.php` requires positive integer limits: **600 requests/minute per IP** before any owner lookup and **120 requests/minute per valid credential**. Exhaustion returns generic 429 with `Retry-After`. Missing/invalid configuration raises rather than disabling protection. Keys contain only hashed IPs or token digests; credential rotation creates a different credential-limit key. The existing shared application cache is the limiter's backend; isolated test servers deliberately use their own array cache. Anonymous IP quotas are separate from the future plan-specific authenticated API limits.

## Ownership, locks and persistence

`monitor_heartbeats` stores one row per monitor: owner, nullable credential digest, positive arming generation, UTC generation start and nullable latest receipt. Composite foreign keys tie owner and monitor together. `MonitorHeartbeat` uses TeamScope and the same fail-closed write context as Monitor; the digest is hidden from implicit serialization. Timing instants preserve microseconds.

`heartbeat_receipts` stores server receipt instants per state/generation, with a composite owner foreign key and unique `(team_id, monitor_heartbeat_id, generation, received_at)` index. The same index supports the latest-at-slot query. Repeated equal-time calls do not duplicate history; normal separate calls renew the rolling window. These are receipt inputs, not CheckResult rows or incident effects. History cleanup remains the retention integration in M7 and must preserve receipts needed by still-valid execution slots; no automatic pruning is added here. Monitor deletion or a change away from heartbeat cascades all associated receipts.

The public receiver performs one restricted unscoped query selecting only state/team/monitor identities by the credential digest. It derives its tenant context from those identities, never from the caller. It then locks **Team → Monitor → heartbeat state**, rechecks the current token and type, and applies `ExecutionEligibility` for pause, plan suspension, quota and minimum cadence. Credential changes and authorized lifecycle actions use the same lock order. A request discovered before a rotation but blocked on its lock is rejected if the token changed before that request acquires the lock.

A valid credential on an inactive/ineligible monitor returns 204 without writing a receipt, updating the latest receipt or clearing the operational restriction. The external job can continue calling while paused. Rate limiting still applies. An accepted active call atomically inserts the server instant and updates the latest receipt. The latest receipt never moves backwards if the wall clock regresses; such a call uses the previous latest instant or generation start and deduplicates accordingly. No notification, result or incident is produced by receipt alone.

## Lifecycle and deadline semantics

New heartbeat monitors arm immediately at creation, even before the first credential is issued or the first call arrives. An explicit credential generation is necessary to configure the external job. Existing heartbeat monitors are backfilled at the migration's database instant with no credential and no historical failure inference.

A real resume, cadence edit or grace edit starts a new generation at the current UTC instant and clears the current-generation latest receipt. Name-only edits, repeated resume calls on an already active monitor, region changes and credential rotation do not rearm. Switching away deletes private heartbeat state; switching back creates a new generation and requires a newly issued credential. Plan reconciliation in M9 must reuse this arming boundary when implementing its future resume transitions.

For a scheduled reference slot, `HeartbeatSnapshotReader` selects the latest receipt **at or before that slot**, within the current generation and active tenant. It does not substitute the summary's potentially later receipt. `HeartbeatStrategy` computes:

```text
baseline = last_receipt_visible_at_slot ?? generation_started_at
deadline = baseline + interval_seconds + heartbeat_grace_seconds
down = reference_slot >= deadline
```

Zero grace adds no tolerance. A receipt exactly at the slot is visible and renews the deadline; one even a microsecond later cannot repair that slot retrospectively. Successive valid calls create a rolling window rather than fixed calendar periods. A missing first call fails at the initial deadline. A late call restores subsequent slots while the earlier missed slot remains missed.

`HeartbeatSnapshot` contains only tenant/monitor identity, generation start and the selected nullable receipt. The pure strategy rejects wrong ownership, unsupported input and future/misordered snapshots. It returns a normal `CheckOutcome` with `heartbeat_missed` for down, null latency and null HTTP status. `observedAt` is actual evaluation time; the context keeps the scheduled reference independently. The future runner must validate monitor revision/eligibility under the existing ledger fence before loading a snapshot: a slot preceding the current generation is stale work, not a target failure. Detection is observed on scheduled slots; this is not a separate deadline-triggered incident daemon.

## Management UI and secrecy

The monitor detail shows configured status, latest receipt, rolling deadline and the existing operational-state badge. Viewers receive these non-secret values but no management controls. Managers may generate, rotate or revoke the credential; rotation/revocation require confirmation.

The issuance response is fetched directly as same-origin, no-store JSON with Laravel's current XSRF cookie echoed in `X-XSRF-TOKEN`. Plaintext enters only ephemeral component state and the explicitly displayed/copyable curl example. It never enters Inertia props/history, session flash, localStorage, sessionStorage or a URL. Hiding the command, leaving/changing the monitor or changing management permissions discards the display; pending requests are aborted on unmount. Regenerating is the recovery path if the response is lost. Copying intentionally writes the command to the user's clipboard only after an explicit click.

The existing secret redactor already covers credential, token, digest and Authorization keys; global flash exclusions also cover the new field names. Controllers and actions never log raw tokens or payloads. Error messages shown by the panel are generic rather than server-provided bodies.

## Verification and rollout

- `HeartbeatStrategyTest`: initial/renewed deadlines, grace, equality boundaries and invalid ownership/reference ordering, without storage or network.
- `HeartbeatTest`: PostgreSQL state/constraints, one-time JSON credentials, policy/tenant denial, rotation/revocation, pause/plan/quota behavior, historical microsecond cutoffs, lifecycle, deduplication, rollback, migration backfill and traffic limits.
- `HeartbeatConcurrencyTest`: real bearer HTTP requests held at a PostgreSQL barrier while receipt/rotation/pause/rearming changes compete. Synthetic committed fixtures and child HTTP servers are cleaned up.
- `HeartbeatPanel.test.tsx`: ephemeral issuance, CSRF request headers, viewer permissions, confirmations, copy/hide/remount, duplicate submission prevention, cancellation and safe error handling.

Run the appropriate files in `php-test`/Node or use `./scripts/verify.sh` for full acceptance. Results are recorded in [TASKS.md](TASKS.md), without implying a remote CI run, browser E2E test or load benchmark. Apply the incremental migration deliberately to the intended development/deployment database before using the updated monitor lifecycle. Automated verification applies it only to isolated test storage. No dependency, environment variable, development key or process change is required. Keep scheduling disabled until the runner/result tasks are complete.
