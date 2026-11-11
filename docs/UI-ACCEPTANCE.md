# Phase A automated acceptance audit

Audit date: 2026-11-11. Scope: UI.01–UI.03 and UI.04.1–UI.04.4, with the automated gate in UI.06.1.
[TASKS.md](TASKS.md) owns completion state and exact final results. UI.04.5, public redesign UI.05 and
identity UI.07 retain their existing dependencies and scope. M3–M5, PDF, customer portal and M9–M12
are not removed or replaced by this audit.

This is a behavioral evidence map, not a statement/branch coverage report or an assertion that every
possible defect is excluded. Test totals and assertion counts are not coverage percentages. DOM tests
exercise installed Inertia/React code with transport boundaries controlled; they do not certify real
browser layout, assistive technology, worker/provider delivery or a live authenticated browser session.

## Criteria and checked-in evidence

| Area / criterion | DOM or pure client evidence | HTTP / PostgreSQL / protocol evidence | Remaining manual boundary |
|---|---|---|---|
| UI.01 inventory and interaction design | [USER-DASHBOARD](USER-DASHBOARD.md), [UI-GUIDELINES](UI-GUIDELINES.md) retain route/data/role mapping and desktop/mobile decisions; these design documents do not claim executable tests | [AUTHORIZATION-MATRIX](AUTHORIZATION-MATRIX.md) and route catalogues identify authorized entrances | Compare real screens and navigation with the current map in UI.06.2–UI.06.3 |
| Semantic primitives, contrast and data tables | [UiPrimitives](../tests/frontend/UiPrimitives.test.tsx), [UiContrast](../tests/frontend/UiContrast.test.ts): required labels/help/errors, unique IDs, native controls/headers, keyboard region, textual unknown states, computed text/border/focus contrast | No new domain read is owned by these components; PHP presenters supply domain values | VoiceOver announcements, native focus, reflow, 390 px and native zoom remain L |
| Team shell and mobile navigation | [AppLayout](../tests/frontend/AppLayout.test.tsx), [MobileNavigation](../tests/frontend/MobileNavigation.test.tsx): route-team links/current section, skip/breadcrumb, explicit account/operator menu, modal Escape/focus/cleanup | [TeamNavigation](../tests/Integration/TeamNavigationTest.php), [TeamAuthorizationMatrix](../tests/Integration/TeamAuthorizationMatrixTest.php): current memberships, every private route/verb, roles and revoked/foreign access | Native dialog, breakpoint, keyboard and browser history acceptance |
| Team switching and private identity boundaries | [TeamSwitcher](../tests/frontend/TeamSwitcher.test.tsx), [NavigationGuard](../tests/frontend/NavigationGuard.test.tsx), [MonitorRealtimeLifecycle](../tests/frontend/MonitorRealtimeLifecycle.test.tsx): pending/lost response, old resource teardown, fresh XSRF, Stay/Discard and identity replacement | [TeamIdentity](../tests/Integration/TeamIdentityTest.php), [TeamMembership](../tests/Integration/TeamMembershipTest.php), [TeamNavigation](../tests/Integration/TeamNavigationTest.php): persisted preference and fresh admission | Real cookies/session expiration, Back/Forward across documents, slow/offline browser network |
| Dashboard and honest first use | [Dashboard](../tests/frontend/Dashboard.test.tsx): real counts/priority, no-team/empty/quota/permission states, unavailable metrics and retained report/chart links | [Dashboard](../tests/Integration/DashboardTest.php), [MonitorMetricProjection](../tests/Integration/MonitorMetricProjectionTest.php): fresh policy/usage and explicit team aggregates | Actual dense/narrow screen reading and navigation |
| Monitor list, search, paging and returns | [Monitors](../tests/frontend/Monitors.test.tsx), [ReadNavigation](../tests/frontend/ReadNavigation.test.tsx): explicit canonical GETs, empty/no-match/out-of-range, retained drafts/results, exact request ownership, duplicate/old callbacks and disposal | [MonitorPages](../tests/Integration/MonitorPagesTest.php), [MonitorMutationNavigation](../tests/Integration/MonitorMutationNavigationTest.php): tolerant filters and safe same-team returns | Native history, reload and URL/cookie behavior |
| Monitor cards and hourly timeline | [MonitorDetail](../tests/frontend/MonitorDetail.test.tsx): zero/missing/unknown/excluded/not-monitored distinctions, safe destinations and quota-aware actions | [MonitorMetricProjection](../tests/Integration/MonitorMetricProjectionTest.php), [MonitorAvailability](../tests/Integration/MonitorAvailabilityTest.php), [MetricBoundaryMatrix](../tests/Integration/MetricBoundaryMatrixTest.php): authoritative duration/SLA/life/maintenance/retention boundaries | Real visual perception and equivalent-table keyboard use |
| Charts, regions and extended history | [MonitorCharts](../tests/frontend/MonitorCharts.test.tsx): requested controls, gaps/zeros/sample counts, equivalent tables, actual owned-read denial | [MonitorMetricProjection](../tests/Integration/MonitorMetricProjectionTest.php), [CheckRollups](../tests/Integration/CheckRollupsTest.php), [MonitorProjectionBenchmark](../tests/Integration/MonitorProjectionBenchmarkTest.php): SQL percentiles and non-averaged duration/statistic projection | Native chart focus/scroll, 390 px/zoom and production load; local query measurements are not an SLO |
| TLS, maintenance, notification and recent-incident context | [MonitorContextPanels](../tests/frontend/MonitorContextPanels.test.tsx): stale TLS provenance, current/upcoming phase, effective policy/channel eligibility, no invented destination and zero/null duration | [MonitorMetricProjection](../tests/Integration/MonitorMetricProjectionTest.php), [MaintenanceWindow](../tests/Integration/MaintenanceWindowTest.php), [EscalationPolicies](../tests/Integration/EscalationPoliciesTest.php): authorized and bounded projections/actions | Actual TLS/DNS/provider behavior and session operation |
| Monitor forms/actions and input contract | [MonitorPayloadContract](../tests/frontend/MonitorPayloadContract.test.ts), [Monitors](../tests/frontend/Monitors.test.tsx), [MonitorMutations](../tests/frontend/MonitorMutations.test.tsx), [HeartbeatPanel](../tests/frontend/HeartbeatPanel.test.tsx): five types, secret replacement, interval validation, owned writes/uncertainty, explicit destructive confirmation and ephemeral secrets | [MonitorLifecycle](../tests/Integration/MonitorLifecycleTest.php), [MonitorCrud](../tests/Integration/MonitorCrudTest.php), [MonitorInputRequest](../tests/Integration/MonitorInputRequestTest.php), [MonitorQuotaConcurrency](../tests/Integration/MonitorQuotaConcurrencyTest.php): real lifecycle, success-to-detail versus validation-to-edit, role/tenant/quota/lock boundaries | Real keyboard/focus, inline errors, slow/offline double-submit and post-response reload |
| Realtime identity, admission and recovery | [LivePageSession](../tests/frontend/LivePageSession.test.ts), [TeamStream](../tests/frontend/TeamStream.test.ts), [RealtimeAcceptance](../tests/frontend/RealtimeAcceptance.test.tsx), [MonitorRealtimeLifecycle](../tests/frontend/MonitorRealtimeLifecycle.test.tsx): native Echo with controlled sockets, coalescing, reconnect, timeout, late JSON, resource/actor/team fences and drafts | [LivePageRecovery](../tests/Integration/LivePageRecoveryTest.php), [BroadcastAuthorization](../tests/Integration/BroadcastAuthorizationTest.php), [RealtimeDelivery](../tests/Integration/RealtimeDeliveryTest.php), [ReverbProtocolSecurity](../tests/Feature/ReverbProtocolSecurityTest.php): private grants, exact payloads, after-commit frames and native signature/origin/protocol rules | Real Reverb/Pusher network, queue workers, session revocation and reconnect; no per-message membership eviction is claimed |
| Incidents and publication | [Incidents](../tests/frontend/Incidents.test.tsx), [IncidentWorkflows](../tests/frontend/IncidentWorkflows.test.tsx): returns, duration/state, owned drafts, private/public labels and irreversible inline confirmation | [IncidentPages](../tests/Integration/IncidentPagesTest.php), [IncidentWorkflowNavigation](../tests/Integration/IncidentWorkflowNavigationTest.php), [IncidentLifecycleMatrix](../tests/Integration/IncidentLifecycleMatrixTest.php): tenant policy, lifecycle and normalized context | Native publication focus/Cancel/Escape, actual timeline changes and duplicate submission |
| Maintenance and operational notifications | [OperationalWorkflows](../tests/frontend/OperationalWorkflows.test.tsx), [Notifications](../tests/frontend/Notifications.test.tsx): snapshot/filter/manual retry, actual monitor/incident links, hidden configuration drafts and delivery semantics | [OperationalLists](../tests/Integration/OperationalListsTest.php), [NotificationPages](../tests/Integration/NotificationPagesTest.php), [NotificationDeliveryMatrix](../tests/Integration/NotificationDeliveryMatrixTest.php), [NotificationQuietHours](../tests/Integration/NotificationQuietHoursTest.php): bounded reads, half-open phases, privacy, dedupe/retry/quiet-hours rules | Real scheduling, email/channel provider, browser timezone and session feedback; provider acceptance is not recipient reading |
| Private status pages and account/team settings | [StatusPages](../tests/frontend/StatusPages.test.tsx), [StatusDomains](../tests/frontend/StatusDomains.test.tsx), [Teams](../tests/frontend/Teams.test.tsx), [Authentication](../tests/frontend/Authentication.test.tsx), [SettingsWorkflows](../tests/frontend/SettingsWorkflows.test.tsx): draft/public boundaries, role/hostname baselines, invitation/ownership confirmation and abortable ephemeral 2FA reads | [StatusPageManagement](../tests/Integration/StatusPageManagementTest.php), [StatusPageIsolationMatrix](../tests/Integration/StatusPageIsolationMatrixTest.php), [PublicStatusPage](../tests/Integration/PublicStatusPageTest.php), [StatusPageSeo](../tests/Integration/StatusPageSeoTest.php), [Authentication](../tests/Integration/AuthenticationTest.php), [TwoFactorAuthentication](../tests/Integration/TwoFactorAuthenticationTest.php): public/password/email/current-identity rules and credential exclusion | Actual authenticator, email verification, DNS/custom-host TLS and password/session browser transitions |
| Operator workspace, audit and ordinary plan/usage | [Admin](../tests/frontend/Admin.test.tsx), [PlatformWorkflows](../tests/frontend/PlatformWorkflows.test.tsx): bounded directory/returns, disposable captured password intent, role/flag separation, audit allowlist and finite member quotas | [PlatformWorkflow](../tests/Integration/PlatformWorkflowTest.php), [PlatformAdminAccess](../tests/Integration/PlatformAdminAccessTest.php), [PlanOverride](../tests/Integration/PlanOverrideTest.php): membership versus operator gate, production 2FA, expiry/precedence, lock/idempotence and redacted plan snapshot | Native inline-password focus/Cancel, real operator session expiration, override mutation and restored fixture state |
| Reports and public-flow regression | [MonthlyReportDownload](../tests/frontend/MonthlyReportDownload.test.tsx), [StatusPageMetadata](../tests/frontend/StatusPageMetadata.test.tsx), [StatusSubscriptions](../tests/frontend/StatusSubscriptions.test.tsx): team-month choice, URL/SEO and opt-in form contracts | [MonthlyReport](../tests/Integration/MonthlyReportTest.php), [StatusSubscriptions](../tests/Integration/StatusSubscriptionsTest.php), public status-page suites: private PDF, explicit published content and email capability rules | Actual PDF download/email/browser documents; later public redesign is UI.05 |

## Demonstrated gap and correction in this audit

The foreground GET hook previously consumed any global Inertia invalid/exception event while it had an
active visit. An unrelated 401/403/404 could hide a still-authorized page, and a programming exception
could be swallowed as a normal search failure. Existing positive-denial fixtures omitted URL attribution
and therefore did not detect that distinction.

[ReadNavigation.test.tsx](../tests/frontend/ReadNavigation.test.tsx) adds fifteen cases using the actual
hook inside AppLayout. Before the fix, nine failed and six passed. The correction records the active
application-built GET URL including scalar controls; candidate and expected URLs are compared after
query ordering/equivalent encoding normalization. Another path, origin, selection, or an unattributed
response is ignored by that hook. Only a matching response-less browser transport exception is consumed;
programming exceptions remain unhandled by the recoverable-read path. The existing generation,
cancellation, explicit retry and access-denial boundaries remain.

The new positives retain previous rows on an owned network failure, recognize owned denials with an
equivalent encoded query, reject duplicate submission and ignore disposed/old callbacks. The existing
Monitors/MonitorCharts fixtures now provide an actual request URL and browser transport shape rather
than treating an arbitrary global error as their request. This change adds no retry, polling, endpoint,
domain policy or dependency; all list/chart callers share the existing coordinator.

## Gate audit

| Gate | Checked-in entry point | Boundary |
|---|---|---|
| Full local verification | [scripts/verify.sh](../scripts/verify.sh) | Compose validation; isolated php-test composer verify; then Node-container npm run verify. Sequential asset build avoids a manifest race with PHP tests |
| PHP formatting and analysis | [composer.json](../composer.json), [.php-cs-fixer.dist.php](../.php-cs-fixer.dist.php), [phpstan.neon](../phpstan.neon) | PHP-CS-Fixer dry-run is the formatter reference, Larastan level 8; no second formatter introduced |
| PostgreSQL/Redis tests | [phpunit.xml](../phpunit.xml), [TestCase](../tests/TestCase.php), [TestDatabaseGuard](../tests/Support/TestDatabaseGuard.php) | Forced test identities, test-only services/opt-in, cached-config refusal, read-only database identity check before migrations and stray HTTP refusal; no SQLite substitute |
| Frontend checks | [package.json](../package.json), [vitest.config.ts](../vitest.config.ts), [quality.test.mjs](../scripts/quality.test.mjs) | Typed ESLint with zero warnings, Prettier, strict TypeScript, actual quality-tool rejection fixtures, DOM suites, production build |
| Test transports | [frontend setup](../tests/frontend/setup.ts), PHP fixture/support boundaries | Fetch/XHR are refused by default; individual tests opt into controlled replies; committed concurrency fixtures target guarded test storage and clean up explicitly |
| CI | [ci.yml](../.github/workflows/ci.yml) | Same locked stack and independent backend/frontend jobs; PHP 8.4 plus PHP 8.5 on main only, as previously decided; backend CI rejects skipped tests |

The local composer test script can still be used in documented offline mode, where integration tests
skip explicitly. Such a run is not the complete gate. For this acceptance audit the canonical script
runs with RUN_DOCKER_TESTS=1 through php-test; actual totals and any skips/warnings must be read from
the final output. Remote CI/PHP 8.5 is not inferred from the local PHP 8.4 run. No manifest, lock,
dependency version, CI branch rule or application environment is changed by this audit.

## Execution record

UI.06.1 is complete. The canonical `./scripts/verify.sh` passes sequential backend/frontend gates:
PHP-CS-Fixer dry-run on 648 files, Larastan level 8, **3315 backend tests / 22956 assertions
(479.51 s)**, ESLint/Prettier/TypeScript, **5 quality-tool tests**, **467 DOM tests in 38 files
(100.13 s)** and the Vite production build. Compiled assets remain present during PHP tests; no
frontend build runs concurrently. Only Markdown changes follow the final gate.

Initial read-ownership reproduction: 9 failed / 6 passed. ReadNavigation plus chart regressions after
the correction: 23 passed; ReadNavigation plus monitor-list regressions: 56 passed (overlapping
coverage, not additive totals). Remote CI/PHP 8.5 and real-browser acceptance were not executed.
These automatic checks do not accept phase A.

## Handoff to UI.06.2–UI.06.4

- Execute the current essential flows in Brave and Firefox with synthetic fixtures and real session
  boundaries, including viewer/non-member/non-operator denial and inline override confirmation.
- Refresh the screen map and detailed instructions while retaining all A–L identifiers in
  [BROWSER-TESTS.md](BROWSER-TESTS.md). Historical A–K results and earlier synthetic probes remain
  historical; they do not certify AppLayout and the current UI.
- Run L01–L08 for native keyboard/focus, actual VoiceOver announcements, 390 px, native 200% zoom,
  slow/offline network, duplicate submission, history, console and the second browser. DOM dialog/focus
  shims and numerical contrast checks are useful regressions, not substitutes for those observations.
- Record outcome, browser/version, limitations and fixture/zoom/network restoration without screenshots
  in the repository. The current manual catalogue still contains historical screen/status notes; reconcile
  those in its assigned acceptance tasks rather than replacing its records with automatic PASS labels.
- Resolve blocking findings and explicitly confirm phase-A exit in UI.06.4 before M9. UI.06.1 completion
  alone does not accept phase A, close UI.04's future billing/API work or certify production behavior.
