# Incident and maintenance pages (M4.05)

M4.05 adds the browser interface on top of the [incident lifecycle and manual actions](INCIDENTS.md) and the [maintenance windows](MAINTENANCE.md). The page patterns follow [MONITOR-UI.md](MONITOR-UI.md). Evidence is in [TASKS.md](TASKS.md).

## Pages and routes

User choice: a team-wide incident list and one page per incident; maintenance windows and the latest incidents of a monitor appear as sections of the monitor detail page.

| Route | URL | Handler | Roles |
|---|---|---|---|
| teams.incidents.index | GET /teams/{team}/incidents | Incidents/Index | owner, admin, member, viewer |
| teams.incidents.show | GET /teams/{team}/incidents/{incident} | Incidents/Show | owner, admin, member, viewer |
| teams.incidents.acknowledge | POST …/incidents/{incident}/acknowledge | `ManageIncidents::acknowledge` | owner, admin, member |
| teams.incidents.updates.store | POST …/incidents/{incident}/updates | `ManageIncidents::postUpdate` | owner, admin, member |
| teams.incidents.updates.publish | POST …/incidents/{incident}/updates/{update}/publish | `ManageIncidents::publish` | owner, admin, member |
| teams.incidents.severity | PATCH …/incidents/{incident}/severity | `ManageIncidents::changeSeverity` | owner, admin, member |
| teams.incidents.root-cause | PATCH …/incidents/{incident}/root-cause | `ManageIncidents::declareRootCause` | owner, admin, member |
| teams.monitors.maintenance.store | POST /teams/{team}/monitors/{monitor}/maintenance | `ManageMaintenanceWindows::schedule` | owner, admin, member |
| teams.monitors.maintenance.update | PATCH …/maintenance/{maintenanceWindow} | `ManageMaintenanceWindows::reschedule` | owner, admin, member |
| teams.monitors.maintenance.destroy | DELETE …/maintenance/{maintenanceWindow} | `ManageMaintenanceWindows::cancel` | owner, admin, member |

`routes/incidents.php` uses the same `auth`, `verified` and `EstablishTeamContext` group as the monitor routes; the two pages also send `Cache-Control: no-store, private` (`NoStoreAuthentication`), because timelines and declared causes are private. `IncidentPageController` renders pages, `IncidentController` and `MaintenanceWindowController` translate mutations. Every mutation redirects back with a status flash mapped to English text in `HandleInertiaRequests`.

Tenant boundaries:

- `{incident}` and `{monitor}` bind through `TeamScope`, so another team's identity is a 404.
- `{update}` and `{maintenanceWindow}` are **scoped bindings** (`scopeBindings()`, relations `Incident::updates()` and `Monitor::maintenanceWindows()`): an entry of another incident or a window of another monitor, even of the same team, is a 404.
- Form Requests authorize before validating (`IncidentPolicy::update` or `MonitorPolicy::update` on the bound model), so a viewer gets 403 whatever the payload. The actions re-run the policy under their locks (ADR-004); the `can.*` props are hints only.

## Input and errors

| Request | Fields |
|---|---|
| `ListIncidentsRequest` | `state` (`open`/`resolved`), `severity`, `page`; invalid values are dropped and listed in `rejectedFilters`, as for monitors, because a failed GET validation would redirect to the same URL |
| `PostIncidentUpdateRequest` | `status` (any `IncidentStatus`), `message` (1–`ManageIncidents::TEXT_MAX_LENGTH` characters) |
| `ChangeIncidentSeverityRequest` | `severity` |
| `DeclareRootCauseRequest` | `root_cause_note`: key required, null or empty clears the cause, at most `TEXT_MAX_LENGTH` |
| `MaintenanceWindowRequest` | `starts_at` (optional, absent = now), `ends_at`, `reason` (1–`REASON_MAX_LENGTH`) |

Lifecycle and past-immutability refusals stay in the actions. The controllers turn `IncidentChangeRejected` into a session error on `incident` and `MaintenanceWindowRejected` into one on `maintenance`; the pages announce both in an alert. Whether a status change is admitted is decided by `ManageIncidents` under lock; the page only offers `postableStatuses` (every status while open, `resolved` alone once resolved, so a resolved incident accepts notes).

**Instants (user decision).** Maintenance input uses `datetime-local` fields in the browser's timezone. The client converts them with `fromLocalInput()` to ISO-8601 UTC; the request accepts only date-times with an explicit `Z` or `±hh:mm` offset (pattern plus calendar check), so the server never guesses a timezone. Pages display every instant in the viewer's locale; the team timezone remains for dashboards (M6).

## Projections

`IncidentPresenter` and `MaintenanceWindowPresenter` build every projection; no model is serialized.

- **Incident row**: id, monitor `{id, name}`, status, severity, cause (stable `CheckError` value), start, confirmation, resolution, duration, whether someone is responsible and the reopen count.
- **Incident detail**: the row plus responsibility instant and responsible user's display name, declared cause, last reopening and the oldest-first timeline. Each entry carries status, message, author display name (null for system entries and deleted accounts), visibility and instants. People never appear by email or identifier.
- **Monitor page**: `recentIncidents` (latest 5 of the monitor) and `maintenance` with `current` (ongoing and upcoming, earliest first, at most `MaintenanceSchedule::CURRENT_LIMIT` = 50), `finished` (latest 5) and `reasonMaxLength`. Each window has a `phase` (`upcoming`, `ongoing`, `finished`) computed with one server instant, which selects the change the interface offers.

`IncidentListing` orders unresolved incidents first, then by start and ID descending, 20 per page, and adds the explicit `team_id` predicate of ADR-003 in addition to `TeamScope`.

## Explicit publication

User choice: every entry is created private. Each private entry has a "Publish update" button that asks for confirmation ("Published updates can appear on public status pages and cannot be withdrawn."). Publication is single and irreversible (`ManageIncidents::publish`, idempotent). The declared cause is never copied into the timeline, so publishing cannot expose it. Public status pages (M8) will read only published entries.

## Accessibility and states

- The list distinguishes an empty team ("No incidents yet") from no matches ("Clear filters", link back to the first page); rejected filters are an alert, the result count is a polite live region and the results region sets `aria-busy` while loading. Pagination reuses the monitor component.
- Status and severity badges are text; tables have captions and `scope` headers.
- The timeline is an ordered list labelled "oldest first"; private and published entries are stated in words.
- Forms have visible labels, `aria-invalid`/`aria-describedby` and inline `role="alert"` errors; buttons are disabled and relabelled while saving. Viewers see no forms or buttons and read "read-only access". Ending an ongoing window or deleting an upcoming one asks for confirmation; editing opens an inline form controlled by an `aria-expanded` button.

## Tests

| Suite | Coverage |
|---|---|
| tests/Integration/IncidentPagesTest.php (PostgreSQL) | List order, state/severity filters and combinations, rejected filters without redirect, page beyond the last, explicit team predicate beyond TeamScope; detail projection with names but no emails, null system author, postable statuses, no-store headers; posting, resolving (event), refused manual reopening and notes after resolution; invalid entries (missing, blank, too long, unknown or array status); viewers refused before validation; acknowledgement and its refusal after resolution; severity, cause declaration, rejection and clearing; single publication and another incident's entry as 404; maintenance with offsets stored in UTC, start now, malformed/past/inverted input; reschedule, end now, delete and finished refusal; another monitor's window as 404; monitor page recent incidents and phases |
| tests/Integration/TeamAuthorizationMatrixTest.php | The ten routes in the role × membership-state matrix, guest/unverified gates, persisted effects of allowed calls, raw snapshots now including incidents, timeline entries and windows, and foreign children under the actor's own parents |
| tests/frontend/Incidents.test.tsx | Empty and no-match states, rejected filters, filter submission with loading, badges and links; read-only timeline for viewers; posting with status, taking responsibility; confirmed single publication; resolved incidents offering notes only and announcing refusals; severity and cause clearing; monitor page sections and empty states; scheduling with browser-local to UTC conversion; editing, deleting and ending windows with confirmation; maintenance refusals |

## Limits

- No real browser drives the pages (same choice as M2.06): DOM tests plus Inertia requests to the production routes.
- Focus is not moved programmatically after a failed submission; errors are announced by `role="alert"`.
- Pages do not update live; real-time updates arrive with M6. A page reflects the state of its last load, and the actions decide under lock.
- Notifications on publication or resolution belong to M5.
