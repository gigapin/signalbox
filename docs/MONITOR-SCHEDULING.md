# Recoverable monitor scheduling (M3.01–M3.07)

This implements ADR-006's scheduling and delivery foundation. [MONITOR-RUNNER.md](MONITOR-RUNNER.md) documents the M3.06–M3.07 application consumer, regional batching, failure ownership and atomic partitioned results. The engine remains disabled by default; incident transitions remain M4. See [TASKS.md](TASKS.md) for verification evidence and subsequent tasks.

## Activation and entry points

`config/monitor-scheduling.php` defines one gate, `MONITOR_SCHEDULING_ENABLED`, false by default. The scheduler and all three commands respect it. Disabled scanning/acquisition services return without accessing the database or broker. Acknowledgement and fenced completion may still settle already acquired work after disabling. The development `.env` is not changed.

| Command | Responsibility |
| --- | --- |
| `monitors:dispatch` | Initialize future slots or atomically reserve the latest due regional executions. |
| `monitors:publish` | Publish committed pending executions whose delivery lease or redelivery delay permits submission. |
| `monitors:recover` | Terminalize expired unstarted work and abandoned running work. |

The three pipeline commands are registered every second, using the Redis scheduler cache with `onOneServer` and a one-minute `withoutOverlapping` lock. The existing `schedule:work` process supports sub-minute operation. Commands run in the foreground; slow scans or brokers can delay subsequent ticks, so this is no punctuality or throughput guarantee. PostgreSQL locks and uniqueness, rather than Redis mutex expiry, enforce correctness.

Keep the gate false until the incremental migrations have been deliberately applied to the intended environment. The real consumer is now bound and tested in the isolated environment; existing development processes and databases are not restarted or migrated by verification.

## Slot and reservation rules

The phase is unsigned `crc32((string) monitor_id) % interval_seconds`. Slots are Unix epoch UTC seconds with that phase, independent of timezone and daylight-saving transitions. An uninitialized slot is set to the first slot **strictly after** the reservation reference instant. At an exact due boundary the current slot is eligible. Late dispatch reserves only the most recent due slot, advances to the next strictly future slot, and never generates a catch-up burst or observations for skipped intervals.

Discovery uses `chunkById`, with 200 candidates per chunk. Scope-free discovery reads only IDs and tenant IDs; each private operation runs inside a bounded `TenantContext`, restored even on error. Reservation, publication leasing and recovery isolate every item: an exception rolls back only that item's transaction, is logged with tenant/item identities and the exception class (never its message), and the pass continues, so a persistently failing low ID cannot starve later monitors or other tenants. `runPass()` returns a `SchedulingPass` with processed and failed counts; `run()` keeps returning the processed count, and the `monitors:dispatch|publish|recover` commands exit with failure when any item failed (FX.03). Reservation locks Team then Monitor, obtains a fresh UTC reference after locking, and rechecks due state and current plan admission. Pause and plan suspension are independent. Quota counts all monitors, with the existing creation-time/ID ordering; cadence uses the current entitlement at the same reference instant, including exclusive trial/override expiry.

Inadmissible monitors receive no new work and are not silently rescheduled to a slower cadence or suspended. M9 reconciliation still owns persisted downgrade changes. A paused/resumed monitor or changed cadence/type starts again with a null slot and therefore a future initialization.

The reservation transaction both advances `next_check_at` and inserts one `check_executions` record per configured simulated region. Each execution has a unique `(team_id, monitor_id, slot_at, region)` identity and an exclusive expiry at the following slot. A composite ownership foreign key prevents cross-team monitor references. Rollback removes the work and cadence change together. The publisher runs separately and cannot see another transaction's uncommitted records.

`monitors.execution_revision` is a positive generation, initially one. The authorized mutation action increments it for execution-setting changes and an actual voluntary pause; label-only changes and idempotent pauses preserve it. This avoids hashing ciphertext, which is re-encrypted during ordinary edits even when its semantic value is unchanged. Reserved work captures this revision; acquisition rejects stale generations, including pause/resume before delivery. Trusted raw SQL/bulk configuration writes must maintain this generation themselves, just as they must maintain other domain invariants; public input cannot assign it. Future M9 reconciliation must increment it for execution-changing mutations.

## Delivery and acquisition protocol

1. Lease a pending execution under its tenant-scoped row lock, using a random publication token and a ten-second lease. Set its next availability to the lease end, then commit.
2. Submit `DeliverCheckBatch(teamId, executions, region)` in bounded groups of two distinct monitors sharing tenant and region to the explicit Redis connection and `checks:eu` or `checks:us`. Payloads contain only scalar identities. No PostgreSQL lock is held over broker I/O.
3. Acknowledge only if the same publication fence remains valid and the work is still pending. Schedule redelivery after five seconds if acquisition never occurs. An uncertain send logs only execution/team identities; broker exception text is omitted because it can include credentials.
4. Before acquisition, lock Team → Monitor → execution; revalidate tenant ownership, expiry, pause/suspension, effective plan, quota and configuration generation. An expired pending execution becomes `missed`; invalidated work becomes `cancelled`.
5. Atomically transition pending → running with a random acquisition fence and a 50-second persistent deadline. Every later delivery is a no-op. No started work is ever republished or reacquired.
6. `ExecutionLedger::finish($acquired, false, $outcome)` atomically terminalizes the owned execution, inserts its genuine result and updates the current-revision monitor summary. Any persistence failure rolls back all three. Completed ledger state alone does not imply an up observation.

Regional envelopes use `tries = 1`, a 45-second worker/Horizon timeout and a 50-second persisted deadline, below the 55-second monitor lock and Redis's 90-second `retry_after`. Shared sorted monitor locks discard overlapping deliveries without acquiring outbox work. Broker UUID/attempt ownership lets a fresh `failed()` hook settle only its own running rows; periodic recovery handles a hard kill. See [MONITOR-RUNNER.md](MONITOR-RUNNER.md) for timing validation and snapshot admission. There is no target retry.

| State | Meaning |
| --- | --- |
| `pending` | Reserved; never acquired. May be submitted repeatedly before expiry. |
| `running` | Exactly one persistent acquisition owner; traffic may or may not have started. |
| `completed` | Owner completed before its deadline; result semantics belong to the consumer. |
| `missed` | Never acquired before the exclusive slot expiry; no observed target outcome. |
| `cancelled` | Admission failed because configuration or operational eligibility changed. |
| `infrastructure_failed` | Consumer infrastructure exception or expired acquisition deadline; no invented target failure. |

Crash before commit leaves no reservation. Crash after commit but before send leaves publishable work. Crash after send but before acknowledgement can duplicate delivery; acquisition remains single. A lost confirmed message is resubmitted while still useful. Crash after acquisition never causes repeated target traffic: recovery terminalizes the abandoned execution. A crash between acquisition and first byte can lose the observation; this is the declared ADR-006 tradeoff, not an exactly-once network guarantee. Late completion, stale publication acknowledgement and wrong acquisition fences cannot overwrite state. Execution-history pruning remains future lifecycle work; no retention or load benchmark is claimed.

## Tests and extension points

- `MonitorSlotsTest`: deterministic phases, 30/60/300/non-divisor intervals, fractional and exact boundaries, timezone independence, delay without drift and invalid input.
- `MonitorSchedulingConfigurationTest`: disabled no-op entry points, gated every-second events and broker and real runner bindings.
- `MonitorSchedulingTest`: multi-chunk tenants, no decryption, regional reservations, pause/quota/cadence, trial/override boundaries, atomic rollback, crash-boundary simulations, lost/duplicate deliveries, fencing, expiry, configuration invalidation and real regional Redis envelopes with a test consumer.
- `MonitorSchedulingConcurrencyTest`: two actual PHP processes wait at a PostgreSQL lock barrier, then compete to reserve, lease or acquire. A concurrent downgrade is respected. Another process cannot lease uncommitted work, and rollback restores the prior slot. Fixtures commit only to the guarded test database and are explicitly cleaned up.

The publisher contract isolates broker submission; `RunMonitorChecks` implements the real consumer boundary. The legacy single envelope remains supported. [MONITOR-RUNNER.md](MONITOR-RUNNER.md) specifies admission, middleware, persistence and failure semantics.


## Checker strategies and runner integration

[HTTP-CHECKERS.md](HTTP-CHECKERS.md), [TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md) and [HEARTBEATS.md](HEARTBEATS.md) document protocol acquisition and pure evaluation. [MONITOR-RUNNER.md](MONITOR-RUNNER.md) integrates them under transactional admission, historical heartbeat snapshots and fenced persistence. Scheduling stays disabled by default.
