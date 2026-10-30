# Private team dashboard (M6.04, M7.03)

`GET /teams/{team}/dashboard` (`teams.dashboard`) uses the existing verified session, `EstablishTeamContext`, `MonitorPolicy::viewAny` and private/no-store middleware. All four member roles may read it; foreign/revoked membership remains 404. Team management and monitor/incident lists link to the screen. No mutation, migration, dependency, environment setting or worker is added.

## Delivered behavior

| Area | Current behavior |
|---|---|
| Monitor states | Disjoint active up/down/unknown, paused and plan-suspended counts, plus total |
| Incidents | Count all unresolved incidents; show at most five, newest first, with links to full history |
| Monitor list | Stable name/ID ordering, 20 rows per page, same-team chart selection |
| Latency | Real hourly means and sample counts, separately for simulated EU/US, over a rolling 24-hour UTC slot window |
| Availability | Time-weighted observed uptime, coverage, SLA and durations of the selected monitor over the same window, plus hourly observed uptime |
| Live updates | Existing Echo subscription and bounded partial recovery; no periodic polling |

## Read boundary and selection

`ShowDashboardRequest` parses positive `monitor` and `page` query values. Page is bounded at 1,000,000; malformed/overflow/array/zero values are ignored and their key names reported, avoiding GET redirect loops. An explicit valid but missing/foreign monitor is a 404. Absent selection chooses the first visible row, or null on an empty/out-of-range page. An explicit monitor can remain selected while navigating another monitor page. Submitted actor/team IDs never establish authority, and reads never change the saved team preference.

`DashboardOverview` orchestrates the read projection and reuses existing monitor/incident presenters. Monitor rows select only identity, operational and timing columns; URLs/headers/body/configuration are not loaded. Chart selection outside the visible page reads only identity/name. Incident summaries omit timeline, contact details and root-cause notes. Every aggregate/related query includes an explicit team predicate alongside TeamScope; a mismatched context cannot bypass it. Counts give plan suspension precedence over voluntary pause, matching `MonitorOperationalState`.

Ordinary read-committed queries do not lock monitoring writes or claim one transactional database snapshot. `generatedAt` is the read-window instant, not a commit watermark. Concurrent changes may be seen by different component queries at slightly different instants; later event-driven recovery reconciles them. Rendering modifies no domain state.

## Latency semantics

`DashboardLatency` issues one grouped PostgreSQL query with explicit tenant/monitor and partition-pruning `slot_at >= start AND slot_at < end` predicates. End is the current UTC instant including microseconds; later recordings are excluded. ISO string bindings retain fractional seconds that Laravel's default DateTime binding would truncate, preventing a just-completed check from waiting for a later event. Exactly 24 rolling one-hour buckets begin at `end - 24h`.

Only observed results with non-null measured `response_time_ms` contribute. Measured failures contribute like measured successes; absent work and null latency do not. Real zero milliseconds is a value. Regions retain separate means (rounded to two decimals) and sample counts; no regional-status or percentile averaging occurs. Historical revisions remain historical measurements in that window; this graph does not calculate uptime. Missing buckets retain `meanMs: null, samples: 0`. Month-boundary queries read the relevant existing partitions. No retention/rollup job is introduced.

`TimeSeriesChart` uses repo-native responsive SVG, keyboard-selectable regions, explicit units, UTC labels and an expandable equivalent data table. Missing/non-finite values break lines; isolated observations remain visible as circles. Accessible names and `aria-pressed` describe the chart and controls. No remote library or generated bitmap is required.

## Availability

`DashboardOverview` asks `MonitorAvailability` for the hours of the rolling window and adds their durations, which needs a single raw-results read. The rules are in [METRIC-ROLLUPS.md](METRIC-ROLLUPS.md#sla-service-m703). Production returns:

```text
uptime.observedPercent = U / (U + D), or null without observed time
uptime.coveragePercent = (U + D) / (U + D + X), or null with a null eligible denominator
uptime.slaPercent      = observedPercent when X is 0, otherwise null
uptime.durations       = { upSeconds, downSeconds, unknownSeconds, excludedSeconds }, or null
uptime.series          = [{ at: bucketStart, value: hourly observedPercent or null }, ...]
```

Percentages are 0–100, rounded down to two decimals. Missing values never become 0 or 100. `durations` is null only when no monitor is selected or the raw results of the window no longer exist; a monitor with nothing to report in the window has zero durations. Seconds are truncated, so they can add up to slightly less than the window. A rolling window rarely has an SLA in practice: it stays null as long as any part of the 24 hours is unknown, including the time before a recent monitor's first observation.

## Live correction and shared JSON fixture

M6.03's validator mistakenly required UUID `execution_id` values while `CheckCompleted`/the ledger use positive integers. That rejected genuine check frames and prevented check-driven refreshes. The validator and synthetic coordinator cases now accept safe positive numeric identities; the backend wire schema/version is unchanged.

`tests/Fixtures/team-broadcast-payloads.json` is the comment-free shared contract: keys are the four `broadcastAs` names; values are their exact version-1 whitelisted payloads from [BROADCASTING.md](BROADCASTING.md). Check values contain numeric monitor/execution/revision IDs, region/kind/status, UTC instants and nullable latency/check time; incident values contain only schema version and monitor/incident IDs. PHP constructs real `TeamUpdate` frames and checks exact fixture equality; TypeScript validates the same values. UUID/string/zero/fraction/unsafe execution IDs are rejected.

`useLivePage` adds `Dashboard/Show: ['dashboard']`, plus the existing identity/runtime roots. Recovery preserves the original monitor/page URL and checks component/team/account. The actual dashboard test feeds the numeric shared payload through the hook/provider, proving authoritative updates, reconnect recovery and idle silence. Regional down is never assigned directly as aggregate status. Existing best-effort and no-server-eviction limits remain; see [REALTIME-CLIENT.md](REALTIME-CLIENT.md).

## Verification

`DashboardTest` covers empty/null states, disjoint counts, secret omission, pagination/selection, actual PostgreSQL means/gaps, region/tenant isolation, month/fractional-second boundaries, future recordings, malformed filters and partial reads after revocation. `teams.dashboard` joins the complete route × role × membership-state matrix and guest/unverified gates. Frontend tests exercise real page interactions, tables, gaps, future nullable metric rendering and numeric-event recovery. Actual gate results and unexecuted browser/live/CI checks are in [TASKS.md](TASKS.md).
