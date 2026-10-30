# Incidents, regional confirmation and timeline

The incident model, its append-only timeline, the status graph and severity derivation were introduced first (M4.01). Regional confirmation, automatic opening/resolution/reopening and the manual incident actions follow (M4.02–M4.03, see [Regional confirmation](#regional-confirmation) and [Manual actions](#manual-actions)). Maintenance windows and pause history are described in [MAINTENANCE.md](MAINTENANCE.md). Routes, pages and Form Requests (M4.05) are described in [INCIDENT-UI.md](INCIDENT-UI.md); durable notifications (M5) are described in [NOTIFICATION-DELIVERY.md](NOTIFICATION-DELIVERY.md), and private broadcast producers (M6.01) in [BROADCASTING.md](BROADCASTING.md). Subscription authorization and the frontend remain M6.02–M6.03.

## Lifecycle

`App\Enums\Incidents\IncidentStatus` follows the status-page convention chosen by the user:

| From \ To | investigating | identified | monitoring | resolved |
| --- | --- | --- | --- | --- |
| investigating | — | yes | yes | yes |
| identified | yes | — | yes | yes |
| monitoring | yes | yes | — | yes |
| resolved | reopen only | no | no | — |

- Open statuses move freely between each other; a same-status assignment is not a transition.
- `resolved` is terminal except for a **reopening**, which always returns to `investigating` and must increment `reopened_count` exactly once and set `last_reopened_at`.
- New incidents always start `investigating`, because they are opened automatically after confirmation (ADR-007).
- Acknowledgement ("presa in carico") is independent of the status: `acknowledged_by` is the responsible user and `acknowledged_at` the instant responsibility was taken. Deleting the user clears the responsible user and keeps the instant.

`Incident::booted()` rejects forbidden status changes and uncounted reopenings even when trusted code assigns properties directly. All attributes are guarded: writes belong to the M4.03 actions.

## Severity and cause

`cause` stores the stable `CheckError` value of the confirming failure; `root_cause_note` is the nonblank cause declared by people and is never inferred. `IncidentSeverity::forCause()` derives the opening severity with an exhaustive `match`, so a new `CheckError` case cannot ship without a severity (and requires a new migration for the `incidents_cause_check` list, enforced by a test):

| Severity | Causes |
| --- | --- |
| critical | `timeout`, `connection_failed`, `resolution_failed`, `tls_failed`, `dns_missing`, `dns_response_failed`, `heartbeat_missed` |
| major | `unexpected_status`, `keyword_mismatch`, `dns_mismatch`, `dns_malformed`, `response_too_large`, `redirect_limit`, `redirect_downgrade`, `invalid_redirect`, `cross_origin_body`, `invalid_destination`, `local_name`, `blocked_address` |
| minor | `tls_expiring` |

When two regions confirm with different causes, `IncidentSeverity::max()` selects the most severe; which cause is stored for disagreeing regions is decided in M4.02/M4.03. Manual severity changes are a later action recorded in the timeline.

## Instants, duration and reopening

All instants are UTC `timestamptz`; the models normalize assigned values to UTC before formatting.

- `started_at`: slot of the first failure in the confirmed sequence; `confirmed_at >= started_at`.
- `resolved_at` and `duration_seconds` are set exactly when the status is `resolved`; `resolved_at >= confirmed_at`.
- `duration_seconds = resolved_at − started_at`, recomputed at each resolution. After a reopening it therefore includes the short recovered gap before the reopening. Precise up/down durations for SLA come from results and coverage (ADR-009), not from this column.
- `config/incidents.php` → `reopen_window_seconds` (default **900**): a failure confirmed within this many seconds after a resolution reopens the same incident; later confirmations open a new incident. The value is read by M4.03.

## Timeline

`incident_updates` stores one entry per timeline event: optional author (null for system entries or deleted users), the incident status after the entry, a nonblank message, and visibility. Entries are **append-only**; corrections are new entries. A PostgreSQL trigger and the model allow only:

- a single publication of a private entry (`is_public` false → true with `published_at`), which cannot be withdrawn or re-dated;
- clearing `author_id`, which the users foreign key performs when an account is deleted.

`is_public = (published_at IS NOT NULL)` is a CHECK constraint. Public status pages (M8) may expose only published entries. `Incident::updates()` returns entries oldest first.

## Database guarantees

`2026_10_28_000013_create_incidents_tables.php`:

- Composite foreign keys `(team_id, monitor_id) → monitors(team_id, id)` and `(team_id, incident_id) → incidents(team_id, id)` keep incidents and entries in the monitor's tenant; both cascade on monitor/team deletion (hard delete decision of M2.04).
- `incidents_single_open`: unique partial index on `monitor_id WHERE resolved_at IS NULL` — at most one unresolved incident per monitor, the last line of defence against concurrent duplicate openings in M4.03.
- CHECK constraints for status, severity and cause value lists, nonblank note, temporal order, resolution coherence, acknowledgement and reopen coherence. Value lists are literals frozen in the migration and compared with the enums by `IncidentModelTest`.
- Both models use `BelongsToTeam`: reads and writes fail closed without a tenant context.

## Regional confirmation

`ExecutionResults::record()` calls the `ResultObserver` contract after writing each terminal result, inside the ledger's Team → Monitor → execution transaction. `IncidentDetection` implements it, so the monitoring engine does not depend on the incident domain, and the incident change commits or rolls back together with the result.

Per region (`monitor_region_sequences`, one row per monitor and region, migration `2026_10_28_000014_create_monitor_region_sequences.php`):

- Results of a superseded `execution_revision` are ignored. A new revision restarts the sequence; every non-name configuration change (including regions and threshold) and every pause already increments the revision.
- A slot not after the region's latest processed slot is history or a duplicate and changes nothing.
- An observed failure continues the sequence only when the previous processed slot is exactly the preceding scheduled slot (`MonitorSlots::latest()`) and was a failure; otherwise it starts a new sequence at this slot.
- An observed success, a missed, cancelled or infrastructure outcome, or a skipped slot interrupts the sequence.

`ConfirmationRule` evaluates a slot only when every configured region processed it in the current revision:

| Verdict | Condition |
| --- | --- |
| confirmed | every region observed a failure and reached `confirmation_threshold` (default 3) |
| recovered | every region observed the target up |
| inconclusive | disagreement, a missing observation, an incomplete slot or a threshold not yet reached |

With one region its own outcome decides. With two regions a recovery in one region alone never resolves the incident, so a missing region keeps it visibly open. `monitors.consecutive_failures` stores the smallest current count among configured regions: the joint progress towards confirmation (user decision).

`IncidentAutomation` applies the verdict and locks incidents after the monitor:

- **Confirmed**, no unresolved incident: reopen the latest incident resolved at or after `now − reopen_window_seconds` (boundary included); otherwise open a new one with `started_at` = earliest first failing slot among regions, the cause of the most severe region (earlier region wins ties) and `IncidentSeverity::forCause()`. Confirmed with an open incident changes nothing.
- **Recovered** with an open incident: resolve it. Otherwise nothing.
- Each change appends a private system entry and dispatches `IncidentOpened`, `IncidentReopened` or `IncidentResolved` (`ShouldDispatchAfterCommit`, identities only). M5.03 listens to publish the committed notification outbox; broadcasting remains M6. A rolled-back transaction dispatches nothing.
- Instants are clamped (`confirmed_at ≥ started_at`, resolution and reopening ≥ confirmation) so a regressing wall clock cannot violate constraints and roll back the triggering result.

A confirmation whose slot lies inside a maintenance window opens and reopens nothing, while recovery still resolves; see [MAINTENANCE.md](MAINTENANCE.md#effect-on-incidents).

## Manual actions

`App\Actions\Incidents\ManageIncidents` locks Team → Monitor → Incident (same order as detection) and re-runs `IncidentPolicy::update` under the locks. Owners, admins and members manage incidents; viewers only read; non-members and other teams' identities raise not-found.

| Action | Behaviour |
| --- | --- |
| `acknowledge` | Open incidents only. Idempotent for the current responsible user; another manager takes over ("Responsibility reassigned."). |
| `postUpdate` | Same status adds a note (also after resolution); moves between open statuses; resolves an open incident (event dispatched). A resolved incident cannot be reopened manually: only a newly confirmed failure reopens it, so resolving while the failure persists leads to an automatic reopening. |
| `changeSeverity` | Records "Severity changed from X to Y."; an unchanged value records nothing. |
| `declareRootCause` | Sets or clears the declared cause; the timeline records only that it changed, never the note text. |
| `publish` | Publishes a private entry once; repeated calls are idempotent and keep the first `published_at`. |

Text is trimmed and must be 1–5000 characters (`ManageIncidents::TEXT_MAX_LENGTH`, shared with the Form Requests of [INCIDENT-UI.md](INCIDENT-UI.md)). Violations raise `IncidentChangeRejected`, which the browser controllers report as a session error on `incident`.

## Notes for maintenance, pages and M12

- Detection evaluates per region and slot (ADR-007) and never derives transitions from `monitors.last_status`.
- Every result transition and every incident action locks the team row `FOR UPDATE`; measure this likely bottleneck in the M12.04 benchmark.

## Tests

- `tests/Unit/IncidentStatusTest.php`: full 4×4 transition matrix, open statuses and lifecycle configuration.
- `tests/Unit/IncidentSeverityTest.php`: severity for every `CheckError`, ordering and `max()`.
- `tests/Integration/IncidentModelTest.php` (PostgreSQL): round trip, tenant isolation and cross-team foreign keys, single open incident, every CHECK, enum/constraint parity, model transition guards and counted reopening, append-only timeline and publication, cascades and user deletion.
- `tests/Unit/RegionalSequenceTest.php`, `tests/Unit/ConfirmationRuleTest.php`: sequence advance, interruptions, skipped slots, stale and duplicate slots, revision reset, one/two region verdicts, incomplete slots, cause/start selection and joint progress.
- `tests/Integration/IncidentDetectionTest.php` (PostgreSQL, real ledger): no incident before the third failure, single opening, interruptions, automatic resolution and duration, two-region confirmation/recovery with incomplete slots, reopening at the exact window boundary versus a new incident just beyond it, superseded revisions, tenant isolation, idempotent repeated confirmation and no event on rollback.
- `tests/Integration/ManageIncidentsTest.php`: role matrix, not-found isolation, acknowledgement and takeover, status updates and manual resolution, rejected manual reopening and invalid text, automatic reopening after manual resolution, severity/cause entries and single publication.
- Migration rollback suites in `MonitorModelTest` and `TeamIdentityTest` include both incident migrations.
- Concurrency: both regions of a slot are serialized by the ledger's Team → Monitor locks; the partial unique index is the final guard. `tests/Integration/IncidentConcurrencyTest.php` (M4.06) proves it with separate PHP processes released together from a PostgreSQL barrier: two regions confirming the same slot open exactly one incident with one entry, and a joint recovery racing a manual resolution leaves one resolved incident.
- `tests/Integration/IncidentLifecycleMatrixTest.php` (M4.06) is the milestone matrix: threshold-three sequences, interruptions, recovery, reopening at and beyond the window, flapping with and without an open incident, regional disagreement, maintenance and late results of older slots. See [Milestone acceptance](#milestone-acceptance-m406).

## Milestone acceptance (M4.06)

| Criterion | Evidence |
| --- | --- |
| Threshold 3: the first failure opens nothing | Matrix rows `first failure`, `second failure`, `third failure confirms` (single region) and `both regions confirm`; joint progress asserted for each. |
| Recovery | `recovery resolves`, `joint recovery resolves`; infrastructure failures neither confirm nor resolve. |
| Flapping | Alternating and two-failure flapping never open; flapping around an incident reopens the same incident (`reopened_count` 2) with one system entry and one event per change. |
| Disagreeing regions | One region always down, regions failing in turn, a disagreement breaking one sequence, infrastructure in one region and one region recovering alone: no opening and no resolution until both agree. |
| Maintenance | A failure contained in a window opens nothing; an incident opened before a window stays single during it and still resolves (plus the cases in `IncidentDetectionTest`). |
| Out-of-order results | A slot completing after the next one is history: it neither extends a sequence nor resolves an open incident; the newer slot starts the sequence and becomes `started_at`. |
| Concurrent results | Two-session races in `IncidentConcurrencyTest`; repeated confirmation is idempotent in `IncidentDetectionTest`. |

Out-of-order semantics follow ADR-007: a newer slot that does not follow a processed slot starts a new sequence, so a late older slot can delay confirmation by the slots it interrupted. This is deliberate: results are never reordered after the fact.

## Transactional notification preparation (M5.03)

Before each public after-commit lifecycle event, `IncidentLifecycle` emits the internal synchronous `IncidentNotificationRequested` event with tenant/incident/monitor identities, cycle and controlled event name. Its notification listener prepares the frozen audience and encrypted outbox inside the same transaction, without network I/O. Failure rolls back the incident and timeline together with notification preparation; repeated requests keep the original audience, including an empty one. These changes do not alter regional confirmation, status transitions or the public event payloads. Apply the incremental `000019` migration before using the updated lifecycle. See [NOTIFICATION-DELIVERY.md](NOTIFICATION-DELIVERY.md).

## Private UI invalidations (M6.01)

The existing after-commit lifecycle events additionally queue identity-only private-team broadcasts through `PublishTeamUpdates`. Their names invalidate current authorized incident/monitor projections; they are not mutable status snapshots or public incident publication. Timeline text, root-cause notes, user details and monitor network targets never enter these frames. The durable notification listener remains independent and a broadcast broker error cannot undo the incident or stop notification intent/publication. Subscription authorization remains M6.02; see [BROADCASTING.md](BROADCASTING.md).
