# Regional runner and results (M3.06–M3.07)

The application now binds `CheckWorkConsumer` and `BatchCheckWorkConsumer` to `RunMonitorChecks`. All five checker variants are integrated. `MONITOR_SCHEDULING_ENABLED` remains **false by default**; verification never enables the development engine, migrates its database or restarts its processes. Apply the incremental heartbeat and result migrations deliberately to the intended database before using this runner. M3.08 still owns the broader engine regression matrix; incidents, notification delivery, rollups and retention remain future work.

## Delivery, admission and private input

Publication leases committed outbox rows and groups each discovery chunk into batches of at most two distinct monitors sharing team and region. `DeliverCheckBatch` contains only `teamId`, `region` and an execution-ID-to-monitor-ID map, plus operational queue options. No model, URL, header, credential or private checker input is serialized. The older `DeliverCheckExecution(teamId, executionId)` remains readable and delegates application work to the same batch admission path; `publish()` remains available for single-envelope compatibility.

Each job installs `UseTenantContext` followed by shared `WithoutOverlapping` locks sorted by monitor ID, keyed by team, monitor and region. Locks use `dontRelease()` and expire after 55 seconds. A busy lock discards this broker delivery without acquiring any execution. Its pending outbox rows can be republished after their existing lease/redelivery delay and eventually become missed at expiry. This is delivery recovery, never a retry of an acquired target check. The ledger fence remains authoritative even if a cache lock disappears. The configured cache store must coordinate workers; the project Redis store supplies that coordination in development/production.

For each valid local identity, prepare its partition before taking domain locks. Admission locks Team → Monitor → execution, rechecks expiry, current eligibility and revision, and captures typed private configuration while those locks remain held. Heartbeat also locks its state after the execution and captures only current-generation receipts visible at the scheduled slot. Each observation has its own short admission transaction; all transactions commit before network work. Mutation after admission may leave a genuine historical observation, but its old revision cannot update the current monitor summary.

HTTP/keyword inputs share the existing `HttpCheckCoordinator` pool. TLS/DNS share `NetworkCheckCoordinator`. Heartbeat uses its pure strategy and captured interval/snapshot. A mixed HTTP/network batch processes the two protocol cohorts sequentially; each cohort retains its own concurrent acquisition. Successfully persisted earlier cohorts survive a later infrastructure exception. Coordinators return a completed cohort, so the runner does not claim early per-response streaming or throughput measurements.

## Timing and failure ownership

| Boundary | Seconds |
| --- | ---: |
| Regional job/Horizon timeout | 45 |
| Persisted acquisition deadline | 50 |
| Monitor cache-lock expiry | 55 |
| Redis `retry_after` | 90 |

`tries = 1` and `failOnTimeout = true`. `CheckRuntimeLimits` rejects batches above two and unsafe configured budgets/visibility before traffic. At the current maximums, the conservative bound is `2 × (10000 + (5 + 1) × 1000) = 32000 ms`, covering sequential protocol cohorts, DNS guard authorization and redirect rounds. The validator reserves additional time inside the 45-second worker boundary. DNS remains separate from measured HTTP network latency.

Acquisition stores the broker payload UUID and attempt alongside the random acquisition token. Ordinary completion uses the acquisition token. Laravel reconstructs the serialized command for `failed()` and restores its queue job through `InteractsWithQueue`: the hook reads that job's UUID/attempt and terminalizes only matching running executions in its scalar envelope. It never trusts properties mutated during `handle()`. Duplicate hooks, wrong attempts, later deliveries and late outcomes cannot overwrite a terminal row. A hard kill without a callback leaves running work until deadline recovery. Malformed private snapshots commit an infrastructure result inside admission before the exception is propagated. Exceptions are infrastructure failures with fixed diagnostics; target failures are ordinary down observations. No domain retry or backoff is implemented.

## Atomic result identity and observation semantics

`2026_10_26_000012_create_partitioned_check_results.php` adds the ledger broker fence, monitor `last_result_slot_at` watermark and `check_results` parent. Partition identity is the immutable scheduled `slot_at`, normalized to UTC, not `checked_at` or insertion time. PostgreSQL enforces:

- Primary key `(id, slot_at)` and unique `(execution_id, slot_at)`.
- Composite foreign key `(team_id, execution_id, monitor_id, slot_at, region, monitor_revision)` to the exact ledger identity. A second month, foreign team or altered revision cannot re-identify the same execution.
- Up/down only for observed results; unknown with no measurement time, latency, code or TLS expiry for every non-observation kind.
- Nullable measured latency rounded to the nearest integer millisecond; nullable HTTP status and verified certificate expiry; fixed safe errors without raw transport messages or response content.
- A parent update trigger, inherited by partitions, rejects all result updates, including partition-key rewrites. Owned lifecycle deletion still cascades from the execution/monitor/team.

| Kind | Health | `checked_at` |
| --- | --- | --- |
| `observed` | `up` or `down` | Actual checker completion/refusal instant |
| `missed` | `unknown` | null |
| `cancelled` | `unknown` | null |
| `infrastructure_failed` | `unknown` | null |

`recorded_at` is independent durable recording time. The ledger terminal transition, result insertion and summary update occur in one transaction. Result correlation or insertion failure rolls back all three. Recovery and failed hooks use the same transaction and lock order. Pre-migration terminal rows receive no fabricated backfill. A legacy direct `finish()` without a `CheckOutcome` retains its historical completed ledger state but records an infrastructure/unknown result; completion alone never invents an up observation.

Reads require `TenantContext` through `BelongsToTeam`. This milestone adds no public result route or API. The private watermark is hidden from generic monitor serialization and cannot be assigned by public monitor input.

## Monitor summary

Only the current execution revision can update a summary, and an earlier slot cannot replace a later watermark. At the latest slot, one configured region reports its observed status; two regions report up/down only once both have matching observed statuses. Partial, discordant or non-observed regional results yield unknown. `last_checked_at` uses an actual observation and never moves backward. TLS summary expiry is the earliest verified expiry available in that slot. Incident counters are untouched.

## Partition operations and retention boundary

`monitors:partitions` prepares the current UTC month and the next two months. The scheduler runs it daily with shared scheduler coordination; it performs no target work and is independent of the engine gate. The ledger also prepares an execution's month on demand, including an old pending slot. `ResultPartitions` serializes creation with a PostgreSQL transaction advisory lock, a two-second lock timeout and verification of the existing relation's parent/bounds. Failure aborts the operation rather than silently writing to a fallback table.

No partition is dropped here. Because an observation in the following month can belong to an earlier slot partition, M7 must reconcile raw retention measured from observation/recording time with monthly slot bounds before cleanup. Dropping a month solely because its slot boundary is old would not implement an exact sliding 30-day raw retention policy. Execution pruning must likewise respect retained results and their cascading ownership.

## Verification boundaries

`MonitorRunnerTest` covers all five variants, duplicate delivery, foreign identity/region refusal, regional agreement, revision changes, atomic rollback, immutable updates, month boundaries, stale-slot summaries, broker ownership, shared-lock skips, scalar batch publication and a genuine two-request socket barrier. Its committed fixtures run real Redis worker processes for exceptions, timeout alarms and SIGKILL, then check recovery without target retries. `MonitorSchedulingConcurrencyTest` adds concurrent partition creation and recovery/failed-hook races at observed PostgreSQL lock barriers. Tests use only guarded synthetic PostgreSQL/Redis data and loopback sockets; committed fixtures are cleaned explicitly.

### Integrated acceptance (M3.08)

The [engine acceptance matrix](TESTING.md#integrated-engine-acceptance-m308) connects scheduled checker sequences and historical heartbeat semantics to the real publisher, Redis envelopes and persisted results. Persistent worker tests cover tenant restoration after exceptions, independent regional progress under a gated target and interprocess Redis monitor-lock exclusion followed by outbox redelivery. These tests preserve the existing runtime semantics and disabled development gate; no new public API or migration is introduced.
