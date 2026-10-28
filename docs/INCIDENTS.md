# Incidents and timeline model (M4.01)

M4.01 introduces the persistent incident model, its append-only timeline, the status graph and severity derivation. It does **not** open, resolve, acknowledge or reopen incidents: threshold and regional evaluation belong to M4.02, the transactional actions to M4.03, maintenance windows to M4.04 and pages/policies to M4.05. No route, job or UI is added, so the authorization matrix is unchanged.

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

## Notes for M4.02–M4.05 and M12

- Evaluate per region and slot (ADR-007). Do not derive transitions from `monitors.last_status`, which stays `unknown` between the first and second regional result of the same slot.
- Every result transition locks the team row `FOR UPDATE`; incident actions should keep the existing Team → Monitor lock order and measure this likely bottleneck in the M12.04 benchmark.

## Tests

- `tests/Unit/IncidentStatusTest.php`: full 4×4 transition matrix, open statuses and lifecycle configuration.
- `tests/Unit/IncidentSeverityTest.php`: severity for every `CheckError`, ordering and `max()`.
- `tests/Integration/IncidentModelTest.php` (PostgreSQL): round trip, tenant isolation and cross-team foreign keys, single open incident, every CHECK, enum/constraint parity, model transition guards and counted reopening, append-only timeline and publication, cascades and user deletion.
- Migration rollback suites in `MonitorModelTest` and `TeamIdentityTest` include the new migration.
