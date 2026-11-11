# Maintenance and notification workflows

UI.04.2 adds private operational read views and integrates notification configuration with the shared
request lifecycle. [TASKS.md](TASKS.md) records actual verification and remaining browser acceptance.

## Routes, permissions and navigation

| Entry | Purpose | Current roles |
|---|---|---|
| `GET /teams/{team}/maintenance` | Team maintenance snapshot, linked from desktop/mobile navigation | All members |
| `GET /teams/{team}/notifications` | Channels, verification, quiet hours and escalation policies | All members read; owner/admin manage |
| `GET /teams/{team}/notifications/deliveries` | Team delivery outcomes, linked from Notification workflows | All members |

All reads run behind authentication, verified email, EstablishTeamContext, current-membership policy
checks and no-store responses. The route team is authoritative. The exhaustive authorization catalogue
includes the two new routes. See [AUTHORIZATION-MATRIX.md](AUTHORIZATION-MATRIX.md).

Maintenance rows link the actual monitor's existing Maintenance panel. Scheduling, rescheduling,
deleting future windows and ending ongoing windows still use the existing monitor-scoped actions.
Viewers inspect the same detail without mutation controls. No team-wide scheduling action is added.
Delivery rows link their actual incident for its timeline and detailed recorded outcomes. A deleted
channel retains its family and history. No retry-delivery or test-notification action is offered.

## Bounded reads and snapshot semantics

MaintenanceListing and DeliveryListing each return twenty rows and an exact total. Both add explicit
team predicates alongside TeamScope and constrain related incident/monitor identities. Eager relations
contain identities/names only. Relation query counts stay constant with one versus twenty visible rows:
three queries for the maintenance service and five for deliveries, as asserted by isolated PostgreSQL
tests. These service counts exclude controller, authentication and shared navigation work; they are not
production latency or load measurements.

`q` is a trimmed literal substring of the monitor name, case-insensitive on PostgreSQL. Percent and
underscore have no wildcard meaning. The term is at most 100 characters, with no control characters.
`state` accepts upcoming/ongoing/finished for maintenance and pending/sent/failed/suppressed for
deliveries. `page` is a positive one-based integer with at most nine digits. Invalid controls are dropped
and reported by name without redirecting a failed GET; unknown parameters are ignored. All predicates
are conjunctive. OperationalListFilter owns bounded parsing, while each Form Request owns the domain
vocabulary and current-membership policy. The query services remain separate domain reads.

Maintenance uses one UTC snapshot for filtering and presentation: upcoming means start > now, ongoing
means start <= now < end, finished means end <= now. Newest start sorts first, with ID breaking ties.
Deliveries sort newest recorded time first, null timestamps last, then descending ID. Empty/out-of-range
pages keep their accepted filters and offer a first-page link. Pagination uses only previous/next links,
so an adversarially large page cannot allocate a list of page-number controls.

These new views are explicit snapshots, with their update time and Refresh results control. Navigation,
Apply filters and refresh use useReadNavigation; failures retain prior rows and unaccepted selection,
and retry is manual. Refresh reads the accepted current page rather than silently applying a draft.
No polling, new realtime root, queue or provider transport is introduced. Times are browser-local.

## Privacy and outcomes

DeliveryListing selects an explicit column allowlist. It never loads encrypted message_payload,
dedupe_key, destination_hash, message_uuid, cycle or claim/lease metadata. NotificationPresenter owns
the existing redacted response projection. Sent means provider acceptance, not recipient reading.
Pending/failed/suppressed never claim delivery. A missing sent timestamp is Not recorded. The incident's
existing oldest-first bounded log remains unchanged; the team list provides paginated newest-first
access across incidents.

## Configuration workflow

Notifications links channels, escalation policies, recorded deliveries and monitor policy assignment.
Instructions guide creation → verification → policy → monitor override/team default. Channel and
policy counts retain their existing backend-enforced bounds (25 channels, 10 policies). Name search is
local to this bounded configuration page; filtered rows use hidden without unmounting editors, so
filtering does not erase drafts. Secrets are never placed in a filter, URL or persisted browser storage.

Channel/Policy/QuietHours forms and row actions now reuse useOwnedMutation and MutationFeedback:
synchronous duplicate suppression, pending input freeze, explicit refused/uncertain/accepted states,
draft retention and review before another manual attempt. A verification code is cleared only after
confirmed acceptance. Activation derives its payload from the current channel state rather than the
initial render. Editors disappear when management permission is lost. Existing native destructive
confirmations remain; final browser focus/keyboard acceptance stays with UI.06.

Webhook signing-secret rotation remains an ephemeral JSON mutation, outside Inertia/history/session
props. The request is single-flight; leaving aborts its transport and removes the revealed value.
401/403/404 trigger the common access-denied boundary. A response loss or invalid response blocks a
second rotation until reload, and explains that the previous secret may already be invalid. Rotation is
never automatically replayed. This flow is separate from retryable notification delivery jobs.

No migration, dependency, environment setting, provider call or worker restart is required. Rebuild the
normal frontend assets and reload the application. Browser/VoiceOver/native zoom, real sessions and
provider delivery require their separate acceptance evidence; DOM/HTTP doubles do not certify them.
