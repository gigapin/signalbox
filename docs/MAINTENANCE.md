# Maintenance windows and pause history (M4.04)

Planned maintenance and historical pauses are the excluded time of ADR-009: the SLA denominator removes the union of voluntary pauses, plan suspensions and maintenance windows, all as half-open UTC intervals `[start, end)`. Maintenance also prevents incidents from opening. Pages, routes and Form Requests come later (M4.05), notification silencing with notifications (M5) and the SLA computation with metrics (M7).

## Maintenance windows

`maintenance_windows` (migration `2026_10_28_000015_create_maintenance_windows.php`) stores one window per row for one monitor: `starts_at`, exclusive `ends_at`, a nonblank `reason` and the scheduling user (`created_by`, cleared when the account is deleted). A composite foreign key keeps the window in the monitor's team and cascades with it. Overlapping windows are allowed; readers merge them.

`App\Actions\Incidents\ManageMaintenanceWindows` keeps the past immutable (user decision):

| Action | Rule |
| --- | --- |
| `schedule` | Start now (`null`) or later; a start in the past is rejected. End after start. |
| `reschedule` | Only before the window starts; same interval rules. |
| `cancel` | A future window is deleted; an ongoing window ends now; a finished window is rejected. |

Reasons are trimmed and must be 1–1000 characters (`REASON_MAX_LENGTH`). Every change locks Team → Monitor → window and re-runs `MonitorPolicy::update` under the locks: owners, admins and members manage maintenance, viewers are denied, other teams' identities are reported as missing. Violations raise `MaintenanceWindowRejected`.

`App\Services\Incidents\MaintenanceSchedule::activeAt()` tells whether a window covers an instant.

## Effect on incidents

A **confirmed** slot verdict whose slot lies inside a maintenance window opens and reopens nothing (user decision). Results are still recorded and regional sequences keep counting, so when a failure outlasts the window the first confirmed slot after it opens the incident; `started_at` remains the first failing slot of the sequence, which may lie inside the window. A **recovered** verdict resolves an open incident even during maintenance.

## Pause history

`monitor_pause_periods` (migration `2026_10_28_000016_create_monitor_pause_periods.php`) records `voluntary` pauses and `plan_suspension` periods separately; they may overlap. The trigger `monitors_record_pause_periods` runs after every insert of a monitor and every update of `is_paused` or `is_plan_suspended`:

- a flag becoming true opens a period of its kind at `now()`, the transaction instant;
- a flag becoming false closes the open period of its kind;
- unchanged flags record nothing, so repeated pause or resume calls are idempotent.

Because the database is the single writer, every path records history: `ManageMonitors`, future plan reconciliation jobs and bulk updates. `MonitorPausePeriod` is read-only in the application. A partial unique index allows one open period per monitor and kind. Monitors already paused or suspended when the migration runs receive an open period starting at the migration instant, because their real start is unknown.

Tests that fix the application clock see pause instants at the real database transaction time; metric tests build history explicitly.

## Excluded time for SLA

`App\Services\Metrics\ExclusionPeriods::between()` returns, for one monitor of the active team, the merged excluded intervals clipped to a requested window; still-open pauses end at the window end. `App\Data\Metrics\TimeInterval::union()` merges overlapping and adjacent intervals so excluded time is never subtracted twice. The SLA service (M7) combines this with observed up/down/unknown time.

## Tests

- `tests/Unit/TimeIntervalTest.php`: union of disjoint, adjacent, overlapping, contained and chained intervals, clipping, invalid intervals and UTC normalization.
- `tests/Integration/MaintenanceWindowTest.php`: scheduling rules, past start and invalid reasons, roles and foreign identities, rescheduling only before start, cancelling future/ongoing/finished windows, database constraints and cascade.
- `tests/Integration/PausePeriodTest.php`: pause/resume through `ManageMonitors`, idempotency, bulk plan suspension, overlapping kinds, monitors created paused, single open period, rejected application writes, cascade and migration backfill.
- `tests/Integration/ExclusionPeriodsTest.php`: merged pauses, suspensions and maintenance, clipping, open periods and tenant isolation.
- `tests/Integration/IncidentDetectionTest.php`: no incident during maintenance, opening once the failure outlasts the window, resolution but no reopening during maintenance.
