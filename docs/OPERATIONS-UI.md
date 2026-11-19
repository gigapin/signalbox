# Maintenance and notification workflows

UI.04.2 adds private operational read views and integrates notification configuration with the shared
request lifecycle. [TASKS.md](TASKS.md) records actual verification and remaining browser acceptance.

## Notifications submenu and dedicated pages — 2026-11-19

The shared desktop/mobile TeamLinks group now contains Channels, Escalation policies, Notification
deliveries and Assign a policy to a monitor. The parent Notifications link opens the compatible
Channels entry; its separate native button expands/collapses the submenu by keyboard. Active child
identity is explicitly supplied by each page, with exactly one aria-current link. Hidden children
leave the tab sequence; ordinary mobile child visits close the dialog and retain the heading-focus
behavior. Submenu toggling itself does not navigate or discard drafts.

| Workflow | GET path suffix under `/teams/{team}/notifications` | Page |
|---|---|---|
| Channels | `/channels` | Notifications/Channels |
| Escalation policies | `/policies` | Notifications/Policies |
| Notification deliveries | `/deliveries` | Existing Notifications/Deliveries |
| Assign a policy to a monitor | `/assignments` | Notifications/Assignments |

The original `/notifications` remains a 200 Channels alias, and the old frontend Index export remains
available for existing history entries. ChannelsPage composes ChannelForm/ChannelRow with shared Modal/SearchPicker/Pagination;
ConfigurationPage now owns only the existing PolicyForm/PolicyRow workflow. Each has its own local search and page lifecycle; filtering still hides rows without unmounting editors, while navigation
between pages uses the existing unsaved-draft guard. The four old in-content workflow links are
removed. The bounded safe channel/policy projection is reused for compatibility and policy editing.

Assignments exposes safe local monitor choices and loads one selected monitor's existing
monitorEscalation projection. It reuses EscalationEditor in the shared Modal and the existing monitor PATCH action rather
than introducing a bulk mutation or new permission. Read navigation uses the shared PageLoader/error
lifecycle; the default/override semantics, refusal handling and form protection are unchanged.
Monitor configuration, channel credentials and foreign identities are never projected by this page.
No migration, package, provider call or development data change is required.

## Channel collection presentation — 2026-11-19

Channel creation is a heading action opening the shared native Modal, with the same ChannelForm and
mutation endpoints. Ordinary close retains the draft; pending work prevents dismissal; confirmed
acceptance closes/resets the editor. Form failures remain visible in the dialog. The shared Select
portal stays inside its nearest open dialog instead of the inert background document body.

The heading SearchPicker replaces the old search card, offers safe channel-name suggestions on
focus/click and navigates locally to the chosen card. Each channel uses a larger-text .ui-panel card.
The existing bounded channel projection remains unchanged. Local ten-item pagination appears only
for more than ten matching channels; hidden rows stay mounted to preserve drafts and verification
values. Search/page changes do not issue HTTP requests or change the domain permission/quota rules.
The First add a channel introduction has been removed. Policies shares the same NotificationCollection workflow, preserving its existing PolicyForm/PolicyRow mutations.

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

## Maintenance selector and compact presentation — 2026-11-19

The Maintenance page omits the redundant subtitle, introduction/link and result timestamp summary.
It uses the shared compact DataTable presentation, preserving the accessible table/scroll-region name
and keyboard scrolling. Refresh is a list-owned Button with a decorative SVG in PageHeading's action
slot; its portal remains within the removable authorized page lifecycle. It refreshes the accepted
selection and current page, never an unapplied draft. Apply filters and Refresh disable their buttons without changing text or replacing their icons;
a shared PageLoader portal shows a spinner at the viewport center, outside document flow. Results
remain mounted, and preserveState/preserveScroll remain enabled. TeamSwitcher announces the request lock without inserting a visible header row, avoiding
a busy-state layout shift. Single-page pagination is omitted; previous/next
and out-of-range recovery remain. Delivery views use the compact heading variant described below.

MaintenancePageController projects `monitorOptions` as sorted `{id, name}` pairs for every monitor of
the authorized route team, including those without windows, with explicit team predicates and
TeamScope. No configuration or secret is selected. The Monitor name SelectField offers All monitors
and filters by `monitor_id`, avoiding ambiguity for duplicate names. `monitor_id` accepts a positive
decimal string of at most 18 digits, is parsed by OperationalListFilter only when enabled by
ListMaintenanceRequest, and is returned as `filters.monitorId`. Invalid values are ignored/reported by
name; a valid ID for another team yields no rows and exposes no foreign names. MaintenanceListing
conjoins the exact ID with the existing tenant/name/state predicates.

Existing `q` bookmarks remain supported: the picker shows their accepted search as a temporary option
until the user selects a monitor or All monitors. Selecting a monitor clears the old name query;
refresh, pagination, errors and explicit filter clearing preserve the same owned-read semantics.
Delivery filtering is unchanged. No migration, dependency or application-service restart is required.

## Bounded reads and snapshot semantics

MaintenanceListing returns twenty rows per page; DeliveryListing returns ten. Both include an exact total. Both add explicit
team predicates alongside TeamScope and constrain related incident/monitor identities. Eager relations
contain identities/names only. Relation query counts stay constant with one versus a full page of visible rows:
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

## Consistent notification destinations — 2026-11-19

NotificationCollection now owns the shared heading SearchPicker, guarded creation Modal and local
Pagination for both Channels and Policies. Rows remain mounted while filtered or off-page; ordinary
modal dismissal retains drafts, confirmed acceptance resets them, and quota/permission loss removes
the create editor. Policies retain the configured maximum of ten; pagination appears only if the
matching collection exceeds ten. Policy cards use the same 24px spacing and readable typography,
with explicit Team default (success) and Custom policy (info) badges. Redundant introduction text
and the separate search/create cards are removed.

Deliveries uses OperationalList's optional controlsTarget to put compact monitor-name and state
filters, Apply filters and the existing icon Refresh in PageHeading. Compact mode removes snapshot
summary text and single-page navigation. Server pages contain ten redacted records, ordered as before;
bounded previous/next links and out-of-range recovery remain. Cards retain incident links, channel
history, reason, attempts, local timestamps and redacted response. The timestamp label explicitly says
Accepted by provider rather than implying recipient reading. DeliveryStatusBadge is shared with the
incident DeliveryLog: pending warning, sent success, failed danger, suppressed info, unknown neutral.
Refresh retains accepted filters/current page; requests use the existing central loader and keep rows
mounted on failure. Maintenance retains its existing filter-card presentation and twenty-row pages.

Assignments places the existing safe team-monitor choices in a heading SearchPicker. Selection loads
one exact monitor through the existing guarded GET; search alone does not change assignments. A
readable card shows the effective policy and No notifications / Team default / Monitor override badge.
Authorized monitor managers open Assign policy in the shared Modal; the existing EscalationEditor
retains clean-baseline synchronization, dirty protection, unavailable-policy validation and PATCH
endpoint. Dismissal preserves the unsent choice; pending work prevents close; field refusals remain
in the dialog and accepted saves close it. Viewers retain only the current card and monitor search.
