# Application bootstrap reference

## Incident workflow handoff — 2026-11-11

UI.04.1 integrates incident list/detail with shared tables/styles, retained list/monitor context, private timeline forms, responsibility, inline irreversible-publication confirmation and redacted delivery outcomes. Status/severity/cause drafts survive realtime updates; pristine forms rebase without an unsaved warning. Exact incident identity is fenced during recovery and a changed resource remounts its forms. The existing mutation coordinator is shared as useOwnedMutation, and MutationFeedback moved from monitors/ to components/; monitor behavior/payloads remain covered by regressions.

New PHP classes are ListReturn, IncidentListReturn and IncidentListSelection; normal controller/container autowiring requires no route, migration, package, environment or worker change. Rebuild/reload the normal frontend assets. Source preparation introduces no new outbound transport or domain transition. TASKS.md owns final checks and limits. Next: UI.04.2, maintenance listing and operational notification navigation. UI.04 stays in progress, including its later billing/API portion; final browser L remains UI.06. This handoff supersedes earlier next-task instructions below.

## Realtime monitor handoff — 2026-11-11

UI.03.8 completes UI.03. One native Echo/live session recovers the exact metric/filter/return URL without polling. LivePageSession rejects a mismatched route monitor identity. AppLayout remounts private children on team/actor change, even with preserveState, and hides private DOM/document title on denial or explicit logout. Escalation choices survive dirty/pending background updates while pristine selectors follow the server; deleted choices remain explicit. Maintenance editors are removed when permission or upcoming phase ends.

No PHP application source, schema, route, event contract, dependency or environment change is needed. Rebuild/reload normal frontend assets to adopt the fixes. Tests use native Echo/Reverb code with controlled socket/HTTP boundaries and guarded test PostgreSQL; real network reconnect, workers, browser/VoiceOver and remote CI remain separate. Final evidence is in TASKS.md. Next: UI.04.1, incident list/detail workflow; UI.06 retains final L acceptance. This handoff supersedes the earlier UI.03 restart instructions below.

## Monitor form/action handoff — 2026-11-11

UI.03.7 introduces MonitorActions, MutationFeedback, useMonitorMutation and a shared response-less transport classifier. Create/Edit keep raw interval validation and explicit secret replacement, adopt semantic styles/Cancel, and freeze inputs during pending writes. Pause/Resume/Delete use inline confirmation with Cancel/Escape focus behavior. Uncertain outcomes preserve drafts and require checking saved state and explicit acknowledgement before another manual attempt; no retry is automatic.

MonitorController now returns successful updates to detail and preserves server-normalized list context for Create/Update/Delete. MonitorInputRequest removes query-only returnTo from domain validation while still rejecting a body/file field of that name. No migration, new package, configuration, queue or development-data update is needed; rebuild/reload client assets as usual. Next: UI.03.8, full realtime recovery/lifecycle audit. UI.06 owns final browser L; current test evidence/limitations are in TASKS.md. This handoff supersedes earlier next-task instructions below.

## Monitor context handoff — 2026-11-11

UI.03.6 adds MonitorContextPanels to Monitors/Show: observed TLS expiry/provenance, ongoing/next maintenance, effective notification steps and safe channel eligibility flags, and compact linked recent incidents. The existing maintenance/escalation forms remain; no new endpoint, query service, migration, package or environment setting. monitorContext joins the existing authorized live partial recovery. Missing browser-history context asks for reload; stale TLS never implies expiry. Next task: UI.03.7, form/action transitions; UI.03.8 and UI.06 remain separate. See TASKS.md for final checks and limitations; this handoff supersedes earlier next-task instructions below.

M0.02 introduces the Laravel 13 application skeleton with Inertia 2, React 19, TypeScript and Tailwind 4. See [TASKS.md](TASKS.md) for remaining work and [DEPENDENCIES.md](DEPENDENCIES.md) for package constraints. The framework configuration and initial migrations were adapted from the [official Laravel 13 skeleton](https://github.com/laravel/laravel/tree/13.x); project-specific source documentation and entry points were added locally.

[ARCHITECTURE.md](ARCHITECTURE.md) records M0.08–M0.09 decisions for future domain boundaries, checker strategies, tenant context, documentation, recoverable scheduling, regional confirmation, retention, SLA and plan reconciliation. These decisions do not imply that the corresponding domain classes or features already exist.

## Monitor charts handoff — 2026-11-10

**Next: UI.03.6, contextual TLS/maintenance/notification/incident panels.** MonitorCharts now adds bounded period/region GET controls and exact regional latency plus time-weighted availability to the existing monitor detail. UTC boundaries, measured samples, null gaps/zero values, equivalent keyboard tables and old-history explanations are explicit. Reusable TimeSeriesChart retains dashboard compatibility. The shared useReadNavigation lifecycle preserves draft/busy guards, filtered returns, explicit retry and private-content removal on denial. No new polling or mutations.

MonitorAvailability shares one raw timeline reader for hourly/daily buckets, bounded to 30 days for daily charts; existing rollup period totals remain authoritative. Cost is material on dense windows: local 172800-result fixture at 30-second EU/US cadence measured 3.17 seconds with rollup totals, 5.30 with fallback, about 278 MiB total warm PHP peak. These are single-request observations, not production/concurrency guarantees. The opt-in command, methodology and historical planner-statistics caveat are in [MONITOR-METRICS.md](MONITOR-METRICS.md).

Backend acceptance: 170 tests/1498 assertions across six relevant suites, Larastan level 8 and four-file PHP-CS-Fixer dry-run passed. Final `npm run verify` passed: 338 DOM tests in 30 files, five quality tests, lint/format/TypeScript and Vite build. Native Chromium checks passed at 390/195 CSS px, including keyboard, dirty Stay/Discard with one GET, reload/history, explicit retry and denial removal; zero mutations/JS exceptions. Temporary browser/server/profile/scripts were removed. This is synthetic frontend evidence, without Firefox/VoiceOver, production backend session, new native zoom test or remote CI/PHP 8.5. Full evidence and limitations are in [TASKS.md](TASKS.md). This handoff supersedes the next-step instructions below; UI.03 remains in progress, with UI.03.7 transitions and UI.03.8 realtime audit still separate. No Git/gh, development data, environment, dependencies or schema changed.

## Monitor availability detail handoff — 2026-11-10

**Historical handoff:** UI.03.4 completion and the initial UI.03.5 handoff are retained below. The current next task is UI.03.6, as recorded above. Monitors/Show now composes MonitorSnapshot, AvailabilityHistory and AvailabilitySummary. Safe destination and authorized actions appear near the heading; filtered return/edit context stays intact. Paused/suspended/current maintenance are separate from the last observed result; missing observation does not render Up. Check timestamps are local, metric windows/bars UTC. No continuous Up/Down duration is inferred.

Cards use existing metrics for 24h/7d/30d observed uptime, coverage, nullable SLA and down/unknown/excluded aggregate durations. History bars preserve all duration categories and outside-lifetime time, with labelled images, patterns, legend and a native keyboard disclosure containing the full equivalent table. Partial observed 100% never paints the whole requested hour up. Old history without metrics and unavailable/zero-life/fully-excluded periods have explicit explanations. Percentage formatting preserves source precision if rounding would overstate availability. Existing settings, maintenance/escalation/heartbeat forms and recent incidents remain; UI.03.6 owns their contextual presentation.

useLivePage now includes metrics in the existing Monitors/Show partial read, so cards share the current authorized snapshot without another timer or fetch coordinator. Only id/name/type flow from the removable live child to the shell heading; rename refresh updates h1/Head. Forms and live resources remain removable on switch/denial. Existing mutation endpoints/payloads/confirmation stay unchanged; a paused monitor outside quota cannot activate Resume.

Final host `npm run verify` passes: ESLint/Prettier/TypeScript, **5 quality tests**, **330 DOM tests in 29 files**, Vite build. MonitorDetail adds **26 cases**, including pure formatting boundaries, partial bars, missing/excluded/lifetime states and live identity/cards/denial. Relevant isolated PostgreSQL suites MonitorMetricProjection/MonitorPages/MonitorAvailability pass **84 tests / 885 assertions**, including the new metrics partial-read protocol test. PHP-CS-Fixer dry-run on that test passes. No PHP application source changed; Larastan was not rerun. Six Markdown documents parsed and links/roadmap/CSS checked.

Brave headless (Chromium 157.0.8103.39), compiled assets and synthetic-only Inertia fixtures verify 390/195 CSS-pixel document reflow, actual bar proportions, Enter disclosure/Tab into its locally scrolling table, long names/destinations and manager controls at 390, maintenance/state separation and unavailable/old-history views. Zero JS exceptions. Browser/server stopped and owned scripts/profile removed; no screenshots/user browser settings touched. This does not certify real sessions, Firefox, VoiceOver, native zoom or final L. Full backend suite and remote CI/PHP 8.5 not run; isolated services reused and fixtures rolled back. No Git/gh, schema, environment, dependency or development-data changes. Read UI-GUIDELINES and MONITOR-METRICS before extending these cards; earlier UI.03.4 handoffs below are historical.

## Monitor list handoff — 2026-11-10

**Next: UI.03.4, monitor detail heading and metric cards. UI.03.3 is complete.** Monitors/Index now uses semantic controls/DataTable, redacted destination text, explicit missing-check/heartbeat labels and separate no-monitor/no-match/out-of-range states. Role/plan hints stay authoritative; shared Pagination adopts the common button/focus styles. No new list query or metric aggregate was added.

MonitorListSelection extracts the existing bounded filter parser for direct requests and return links. MonitorListReturn reconstructs only the route team's canonical monitor-list URL from a bounded GET hint, stripping invalid/unknown/duplicate/nested controls and fragments; external hosts/schemes, foreign teams and non-list paths fall back. Controller projects monitorListUrl on Show/Create/Edit. Internal GET links carry it for detail return, creation cancellation and editor return to detail, with canonical fallback for old history. General dashboard/incident origins and post-mutation transitions remain UI.03.7/UI.04; mutation action URLs/payloads and auth/password redirects remain unchanged.

useMonitorSearch owns explicit GET lifecycle: single-flight controls, busy guard, owned cancellation, draft preservation on temporary failure and no automatic retry. Late callbacks cannot release a newer request. Authoritative 401/403/404 hides private content through TeamAccessContext. List content keys follow URL/accepted filters so native history restores controls without overwriting a draft during metric-only realtime recovery. Query applies at page one with replace=false.

Final host `npm run verify` passes ESLint/Prettier/TypeScript, **5 quality tests**, **304 DOM tests in 28 files**, Vite build. Relevant PostgreSQL suites MonitorPages/MonitorMetricProjection/MonitorProjectionBenchmark/TeamAuthorizationMatrix pass **945 tests / 10142 assertions**; PHP-CS-Fixer dry-run on five PHP and Larastan level 8 pass. Brave headless (Chromium 157.0.8103.39) compiled-page synthetic probe verifies filtered list→detail→return, reload, native Back/Forward, filter/page restoration, page-one search, empty result, failure with retained draft, explicit retry and denial hiding private data. Document widths equal 390/195 CSS-pixel viewports; zero JS exceptions. Browser/server stopped and owned scripts/profile removed, no screenshots/user browser settings touched. Five Markdown documents parsed and local links/states checked.

No full backend suite, remote CI/PHP 8.5, Firefox, VoiceOver or native zoom acceptance run; final L remains UI.06. Isolated test services reused, fixture data rolled back; no development data, schema, environment, dependencies or Git/gh changes. Read [UI-GUIDELINES.md](UI-GUIDELINES.md) for return/search contracts and TASKS for evidence. Earlier UI.03.3 restart points below are historical.

## Team overview handoff — 2026-11-10

**Next: UI.03.3, monitor list presentation and filtered return context. UI.03.2 is complete.** Dashboard/Show now composes OverviewSummary, priority incident review, MonitorOverview and monthly-report panels. Team counters link to existing filtered lists; Up/Down/Unknown include `state=active` to match disjoint counts. Snapshot generation, per-monitor last check and connection status remain distinct; local timestamp timezone is explained. No team-wide uptime is inferred from monitor percentages.

DashboardController adds fresh `can.create` (Gate) and `usage` (MonitorLimits) inside the live `dashboard` root. New monitor requires explicit permission and confirmed capacity; missing historical props do not imply either. Quota includes paused/suspended monitors and cadence comes from the effective plan. First use explains missing data and role-aware next steps. Existing dashboard chart selection/pagination URLs survive, including returning from an empty page; the selected chart links to monitor details. MonthlyReportDownload keeps its plain PDF link and native month choice with constrained select width.

Read [UI-GUIDELINES.md](UI-GUIDELINES.md) for presentation contracts and TASKS for final verification. The targeted backend suite covers every tenant role, full quota and permission refresh, plus query-growth assertions. Repeated local query measurement shows **18 dashboard queries at both 1 and 40 monitors**, versus UI.03.1's historical 15 before quota/permission projection; at most 20 monitor rows. No per-row reads introduced. Final host `npm run verify` passes: lint/Prettier/TypeScript, **5 quality tests**, **291 DOM tests in 28 files**, Vite build. Relevant PostgreSQL suites pass **24 tests / 203 assertions**; query benchmark rerun **2 tests / 55 assertions** overlaps that coverage. PHP-CS-Fixer dry-run on two PHP files and Larastan level 8 pass. Five Markdown documents parsed and local links/states checked. Brave headless (Chromium 157.0.8103.39) compiled-page fixture probe passes at 390/195 CSS pixels with no document overflow, first use, permission/quota hints, filtered destinations and zero JS exceptions. A native select overflow at 195 was found and fixed before the final successful probe. This is synthetic frontend evidence, not real-session or native-zoom acceptance; Firefox, VoiceOver, full backend suite and remote CI/PHP 8.5 were not run. Browser/server stopped and owned scripts/profile removed, no screenshots or user browser settings touched. No schema, dependency, environment, development data or Git/gh changes; full browser L remains UI.06.

## Monitor read projections handoff — 2026-11-10

**Next: UI.03.2, team overview presentation. UI.03.1 is complete.** Earlier restart points naming UI.03.1 below are historical. Read [MONITOR-METRICS.md](MONITOR-METRICS.md) for the private contract, provenance, reproducible measurements and limits. New ShowMonitorRequest validates allowlisted period/region without a GET redirect loop. MonitorMetrics composes 24 recent duration bars and 24h/7d/30d time-weighted availability using the existing SLA/rollup authority. MonitorMetricLatency produces regional bucket and whole-window exact statistics in SQL, with at most 62 aggregate rows and no averaging of percentiles. Regional history uses retained raw data for at most 30 days; stored rollups combine regions and cannot supply that distinction.

MonitorContext exposes cached TLS expiry separately from a retained current-revision observation, and effective notification target labels/flags without credentials. Existing maintenance/recent incidents remain intact. List reads select explicit minimal columns and decrypt only visible URLs for immediate redaction; headers/body/keyword are not hydrated. Dashboard summaries/events stay unchanged. Inertia closures avoid the new work when partial reads omit these props. TypeScript contracts are ready for subsequent cards/charts; no new visual acceptance is claimed by the read-model task.

Benchmark fixture: 1→40 monitors keeps dashboard/list/detail query counts at 15/10/36, with at most 20 list rows. Earlier detail baseline was 16 queries without the new metric payload. Thirty-day fixture with 1440 raw observations returns 62 aggregate rows: full detail median 379.68 ms with raw SLA fallback versus 46.08 ms with 720 hourly rollups. These are local test measurements, not production load results or maximum-cadence evidence. Keep aggregation operational; the raw fallback cost remains explicit. Final relevant PostgreSQL suites pass **1003 tests / 10557 assertions**; separate benchmark passes **2 tests / 55 assertions**. PHP-CS-Fixer dry-run on ten touched PHP files and Larastan level 8 pass. Host `npm run verify` passes ESLint/Prettier/TypeScript, **5 quality checks**, **281 DOM tests in 28 files** and Vite build. Five Markdown documents parsed and local links/roadmap checked. Full backend suite, remote CI/PHP 8.5 and browser/VoiceOver/zoom were not run. Only isolated test PostgreSQL/Redis services started; fixtures rolled back and application services left untouched. See TASKS for checks and limits. No schema, dependency, `.env`, development data or Git/gh changes; final browser L remains UI.06.

## Private layouts handoff — 2026-11-10

**Next: UI.03.1, read projections and measured queries for dashboard/monitor metrics.** UI.01 and UI.02 are complete; earlier restart points below are historical. All private team resource pages now use AppLayout. PrivateContextLayout gives account/security/Your teams and platform administration separate explicit contexts, with shared navigation/account menu and no inferred platform tenant. Public/authentication/invitation/onboarding layouts remain dedicated. Read [UI-GUIDELINES.md](UI-GUIDELINES.md) for contracts and [USER-DASHBOARD.md](USER-DASHBOARD.md) for the next read-model scope.

NavigationGuardProvider mounts outside the Inertia App and holds only dirty/busy flags. Private forms keep their own data and accepted baseline; raw sensitive requests and password confirmations also register busy state. Pending work blocks leaving; native Stay/Discard protects links, logout, team switching and history. Domain mutations are never replayed. Authoritative security redirects and acknowledged switches explicitly bypass the gate. Page hooks live inside removable children; switching/access denial removes old forms and live resources, including an open departure prompt.

Bootstrap must call prepareHistoryNavigation before mounting Inertia. Its data-free window dispatcher must precede Inertia's popstate consumer; provider cleanup releases the delegate and restores native history methods. An integer entry position preserves opaque Inertia state without copying fields. Native Back first restores the current entry before asking, so Stay preserves the actual form. Browser testing caught and fixed an earlier listener-order bug that DOM tests had missed. Existing filtered URLs/native history remain supported; dedicated returnTo/list-link refinements remain UI.03.3/UI.03.7.

Final host `npm run verify` passes: ESLint/Prettier/TypeScript, **5 quality tests**, **281 DOM tests in 28 files**, Vite build. Eleven new guard cases include authoritative revocation while a prompt is open. Four Markdown files checked for parsing, links and roadmap consistency. Temporary browser/server stopped and owned fixture scripts/profile removed; details in [TASKS.md](TASKS.md). Brave synthetic compiled-page checks cover Back/Stay draft retention, filtered Back/Discard, Forward, one confirmed switch with old draft removal, account context/reload and 390/195 CSS-pixel reflow; zero JS exceptions. This is targeted frontend evidence, not backend/session acceptance or final L. No PHP changed: PHP-CS-Fixer/Larastan/backend suites not rerun; Firefox, VoiceOver and remote CI/PHP 8.5 not run. No Git/gh, screenshots, dependency/environment or development-data changes.

## Mobile navigation handoff — 2026-11-10

**Next: UI.02.4, remaining private-page migration and explicit account/platform contexts.** UI.02.3 adds MobileNavigation (native dialog), shared TeamLinks and a matchMedia/useSyncExternalStore breakpoint hook. AppLayout renders only one team navigation: sidebar at 1024 CSS pixels and above, header trigger/dialog below. Team selection remains one header-owned instance. Earlier restart points naming UI.02.3 are historical.

Opening focuses Close; Escape and Close restore the trigger. Native modal focus containment/inert background are used, with root scrolling restored exactly on close. Same-tab links close then focus the destination h1 after successful navigation; modified clicks preserve normal browser behavior. Desktop transition, access denial and switching remove the modal safely and focus visible main content. AccountMenu also closes on outside focus, preventing its Escape listener from conflicting with the modal. Native dialog support is exercised in the current Brave fixture probe; no new library or backend behavior.

Final host `npm run verify` passes: lint/Prettier/TypeScript, **5 quality tests**, **270 DOM tests in 27 files**, Vite build. Ten new DOM tests cover lifecycle and focus integration; jsdom prototypes are restored after fixture cleanup. A real Brave headless probe (Chromium 157.0.8103.39) with temporary profile and synthetic-only Inertia server verifies :modal, nine Tab/Shift+Tab containment, inert background, Escape, desktop transition and destination-heading focus; document widths equal viewport at 390 and 195 CSS pixels, zero JS exceptions. Separate native default zoom 200% changes CSS viewport 780→390 and DPR 1→2, without overflow; dialog width 352 and Close visible. Temporary zoom restored to 100%, browser and server stopped, scratch artifacts removed. No screenshots or user browser settings touched.

This is targeted synthetic frontend evidence, not backend/session acceptance or PASS for the final L matrix. Firefox and VoiceOver still belong to UI.06 after page migration. No PHP changed, so PHP-CS-Fixer/Larastan/backend suites were not rerun; remote CI/PHP 8.5 not run. No Git/gh, dependency, environment, development data or application process changes. UI.02.4 must preserve drafts/in-flight guards, tenant boundaries, filtered return paths and separate public/auth/platform layouts; the dashboard is still the only migrated resource page.

## Team selector handoff — 2026-11-10

**Next: UI.02.3, accessible mobile navigation.** UI.02.2 adds private membership choices, per-team roles and confirmed switching to the dashboard shell and Your teams. Account's accessible currentTeam includes an ID for Open dashboard. See [UI-GUIDELINES.md](UI-GUIDELINES.md) and [TEAMS.md](TEAMS.md) for the new JSON acknowledgement and privacy boundaries. Earlier UI.02.2 restart points are historical.

TeamNavigation reads explicit id/name/role choices and a fresh valid preference with two queries per projection, without a fallback team. Shared teamNavigation is limited to verified private team/account routes and null on public/authentication/platform contexts. Private status-page routes now also use NoStoreAuthentication. HTML selection explicitly redirects to the target dashboard; JSON selection acknowledges exactly its relative destination after persistence. Gate/lock/revocation checks remain authoritative and submitted role/redirect fields cannot override them.

Switching suspends the old private children and navigation before sending a single same-origin CSRF POST. The real dashboard's live subscription and pending reads are disposed. Errors/timeouts/uncertain acknowledgements keep data hidden and require an explicit Your teams read, with no replay or optimistic rollback. Recovery authorization denial hides the dashboard, role and obsolete selector while preserving the accessible Live access lost notice. No periodic polling or new revocation event is introduced. Header memberships refresh on complete private visits, not metric-only partial reads.

Actual backend checks: TeamNavigation/TeamMembership/TeamAuthorizationMatrix/Dashboard suites **963 tests / 9843 assertions**, followed after final DI/private-header changes by TeamNavigation/StatusPageManagement **36 tests / 245 assertions** (overlapping coverage, not additive unique totals). Larastan level 8 passes; PHP-CS-Fixer dry-run passes on eight touched PHP files. New selector tests cover transport and real-dashboard lifecycle. Final frontend gate `npm run verify` exit 0: ESLint/Prettier/TypeScript, **5 quality tests**, **260 DOM tests in 26 files** and Vite build. Selector/RealtimeAcceptance targeted rerun passes **29/29** after preserving the denial notice in the shell. Five Markdown documents checked for parsing, local links and roadmap consistency; details in TASKS. No full backend suite, remote CI/PHP 8.5 or real-browser/VoiceOver/native zoom executed; L remains UI.06.

The dashboard is still the only private resource page migrated to AppLayout; Your teams uses the shared selector within its existing account shell. Mobile navigation currently wraps above content below 1024 pixels. UI.02.3 must add its dialog and focus behavior without breaking selector suspension; UI.02.4 will migrate the other pages and connect draft/in-flight guards. No Git/gh, screenshots, development data, credentials, migration, environment or dependency changes. Only isolated PostgreSQL/Redis test services were started for verification; no application process was started or stopped.

## AppLayout handoff — 2026-11-10

**Next: UI.02.2, accessible team selector and confirmed switching.** UI.02.1 adds AppLayout, AccountMenu and typed team destinations in components/app, with semantic shell CSS. Dashboard/Show is the first adopted page and its duplicated local navigation is removed. Read [UI-GUIDELINES.md](UI-GUIDELINES.md) for the layout contract and [USER-DASHBOARD.md](USER-DASHBOARD.md) for flows. Earlier restart points naming UI.02.1 are historical.

The shell takes the controller-authorized route team and section explicitly, uses configured appName, displays a desktop sidebar from 1024 CSS pixels, header, breadcrumb, one h1, skip link and shared status. Account disclosure has account/teams/security, operator-only Admin and a busy-protected logout POST; Escape restores trigger focus and outside interaction preserves the chosen focus. No role or team selector is fabricated; UI.02.2 must project fresh memberships/current role and implement the planned explicit team-selection redirect to its dashboard. No backend props, endpoints, policy or metric changes in UI.02.1.

Below 1024 pixels the navigation is currently visible and wraps above content; UI.02.3 owns the modal mobile menu. The other private pages still use AuthLayout until UI.02.4: following links can therefore reach a legacy layout. Maintenance enters the menu only with UI.04.2; Billing/API and public/platform contexts keep their dependencies. Dashboard report/chart URLs and page-owned useLivePage recovery remain intact. Reload assets when evaluating the new dashboard or use the existing host Vite workflow; no environment reset is needed.

Targeted frontend suites passed **32/32** (AppLayout, Dashboard, RealtimePages, UiContrast). Final host `npm run verify` exit 0: ESLint/Prettier/TypeScript, **5 quality tests**, **242 DOM tests in 25 files**, Vite build. Documentation links/IDs and emitted shell CSS checked. Full details are in the latest TASKS entry. No PHP changed, so PHP-CS-Fixer/Larastan/backend suites are not rerun; remote CI, real-browser/VoiceOver/native zoom remain unverified in this task and L stays in UI.06. No Git/gh, screenshots, data, process, environment, migration or dependency changes.

## UI primitives handoff — 2026-11-10

**Next: UI.02.1, AppLayout and persistent navigation.** UI.01 is complete: inventory, flows and shared primitives. Read [UI-GUIDELINES.md](UI-GUIDELINES.md) for contracts and [USER-DASHBOARD.md](USER-DASHBOARD.md) for the route/data map and hierarchy. Earlier UI.01 restart points below are historical. AppLayout, team selector/mobile navigation and page migration remain UI.02 work.

Available: prefixed semantic dark tokens and opt-in component styles in app.css; Button, InputField, Panel/PageHeading, Badge, Alert and DataTable in components/ui. Monitor/incident badges and the existing TableScrollArea adopt the shared styles; field/table refs and native semantics are preserved. Controls do not own domain requests, retries or metrics. No new library or backend/API change. The public theme has no global CSS override.

Actual host verification: targeted new suites **12/12**, final `npm run verify` exit 0 with lint/Prettier/TypeScript, **5 quality tests**, **224 DOM tests in 24 files** and Vite build. Contrast tests read CSS tokens: 18 normal-text pairs >=4.5:1 and 10 focus/border pairs >=3:1. Four changed Markdown documents are parsed and checked for local links/roadmap consistency; compiled CSS retains the primitives. Backend/PHP checks were not run because no PHP changed; remote CI, real-browser/VoiceOver/native zoom and performance measurements were not run. L01–L08 stays in UI.06 before M9. No Git/gh, screenshots, data, process, environment or dependency changes.

Reuse the primitives for the shell; preserve intended/account and invitation/password continuations, same-team allowlisted return context and old dashboard chart URLs. UI.02.2 owns the explicit team-selection redirect to its dashboard; Maintenance joins the menu only after UI.04.2. UI.07 remains open. Reload built assets when evaluating the current badges, or use the existing host Vite workflow; no environment reset or server restart is required.

## UI flows handoff — 2026-11-10

**Next: UI.01.3, shared tokens and UI primitives with code, tests and UI-GUIDELINES.** UI.01.2 defines the flow and desktop/mobile hierarchy in [USER-DASHBOARD.md](USER-DASHBOARD.md); UI.01.1 inventory remains its factual baseline. Earlier handoffs naming UI.01.1/UI.01.2 as next are superseded. No AppLayout or new screen is implemented by this documentation task.

Decisions for implementation: preserve login intended/account and invitation/password continuations; account gains a verified dashboard link. UI.02.2 will make the existing team-selection POST redirect explicitly to the destination dashboard, with pending/error/revocation handling. Route team remains authoritative. Return links carry a normalized, same-team, allowlisted returnTo context with at most two levels; never feed arbitrary destinations into authentication redirects. Preserve old dashboard chart URLs and filtered-list context through monitor/incident/edit flows.

The hierarchy uses the same reading order on desktop/mobile: sidebar from 1024 CSS pixels, accessible navigation dialog below, monitor contextual column from 1280 pixels when space permits. Empty/no-team/no-observation/loading/error states, draft departure, mutation pending and uncertain transport outcomes are specified. Platform/account/public contexts remain explicit. Maintenance only joins the menu after its team list in UI.04.2; billing/API and naming/logo dependencies remain unchanged.

This task changes only the brief, TASKS and this handoff. Check Markdown parsing, file links, balanced blocks and UI states/IDs; no application tests, build, browser/VoiceOver, PHP analysis or CI are implied. Historical application baseline remains below. Phase A estimate is still 73–108 hours, not measured effort; L01–L08 remains in UI.06 before M9. No Git/gh, dependencies, data, environment, processes or screenshots changed.

## UI inventory handoff — 2026-11-10

**Next: UI.01.2, flows and desktop/mobile hierarchy.** UI.01.1 is complete as a static inventory in [USER-DASHBOARD.md](USER-DASHBOARD.md): routes/screens, role and action matrix, reusable components, card-to-data mapping, gaps and implementation order. The older 2026-11-09 handoffs below are historical where they name UI.01.1 as the next task.

Key gaps: private screens still use AuthLayout with local menus; accessible memberships/current role are not shared globally; team selection currently redirects to account; no standalone team-creation route exists; maintenance has monitor actions but no team list; charts remain on the dashboard. TLS expiry is stored but not projected, and the last check does not prove a fresh certificate observation. Longer metric windows and effective notification channels need deliberate read projections. Preserve existing dashboard chart URLs, filter context, server gates, drafts and event-driven recovery.

Estimated phase A: **73–108 hours**, including inventory, documentation and checks; this is an estimate, not measured effort. No application code, API, dependencies or data changed. No application suites, browser acceptance, build, PHP analysis or CI were rerun for this documentation-only task; the 2026-11-09 baseline below remains historical. Markdown parsing, local links and roadmap consistency are checked for the three changed documents. No Git/gh, screenshots, environment or process changes.

Sequence remains UI.01.2 → UI.01.3 → UI.02 → UI.03/UI.04.1–UI.04.4 → UI.06 including L01–L08 → M9. Naming/logo UI.07 and later billing/API/public refinements keep their existing dependencies.

## Session close and next start — 2026-11-09

**Start at UI.01.1.** The user completed mandatory acceptance blocks A–K, then requested a usable dashboard/application navigation based on the supplied monitor-detail screenshot. The expanded UI macro has seven preserved parent IDs and 27 pending subtasks. It is planned, not implemented. Read [the latest session closure and roadmap](TASKS.md), [the dashboard brief](USER-DASHBOARD.md) and AGENTS/spec/dependency references before implementation.

The next deliverable is an inventory of current routes, layouts, role gates, read models and reusable components, mapping each proposed card/chart/action to real data and identifying gaps. Start with `AuthLayout`, `Dashboard/Show`, Monitors pages, `DashboardOverview`, `DashboardLatency`, `MonitorAvailability`, presenters/policies and `useLivePage`. Record scope, dependencies, first implementation slices and an updated estimate. Then continue with UI.01.2/UI.01.3, AppLayout and dashboard/monitor views.

The session fixed network/offline feedback and mobile overflow, host Echo/Pusher installation, live monitor interval validation with invalid submission blocked, 2FA/override redirects, and inline operator-password confirmation that applies the requested change once. The user subsequently confirmed completion through K. Existing intervals, redirects and password behaviors are regression constraints for the new UI; preserve tenant checks, real metric semantics and realtime recovery.

Last actual local checks: frontend **212 DOM / 5 quality**, lint/format/TypeScript/build; four pertinent backend suites **101 tests / 725 assertions** on isolated PostgreSQL/Redis; PHP-CS-Fixer and Larastan passed. Brave/Firefox verified failed and successful password confirmation, cancellation and single override mutations. These results concern the current implementation, not the future redesign. Full backend suite, remote CI/PHP 8.5 and benchmarks were not run in this session. This documentation-only close does not rerun them.

Sequence: **UI phase A → UI.06 with L01–L08 on the new interface → M9**. The tests in L are deferred, not completed; previous PASS results remain historical. Later billing/API/public refinements keep their dependencies and UI.07 naming/logo remains open without blocking phase A. Optional realtime/custom-domain acceptance is not inferred.

Temporary browser servers on port 18001 were stopped and their test-only identities/teams/audit fixtures removed. Development services, user sessions and Vite were left under user control; recheck their state when needed. Host Vite last used 127.0.0.1:5173 and a separate host node_modules installation. Do not infer current team/monitor/override state from older fixture snapshots. The screenshot stays in the conversation and its structure is described in the brief; no screenshots belong in the repository. Git/gh are user-only. The dated handoffs below preserve history and are superseded by this section where their next step differs.

## Browser acceptance handoff — 2026-11-09

The user confirms all mandatory browser blocks through K and then explicitly prioritizes a usable user dashboard and application navigation before the final accessibility checks. **Next task: UI.01.1**, inventory and design under the expanded UI macro, now scheduled before M9. Start from the monitor-detail screenshot supplied in the conversation, reusing M6/M7 data and existing actions. See [USER-DASHBOARD.md](USER-DASHBOARD.md) and [TASKS.md](TASKS.md). This handoff records planning, not an implemented redesign.

**Defer L01–L08 to UI.06 after the phase A interface is implemented.** Earlier L01/L04–L08 PASS results remain historical; repeat the relevant flows on the new layout, including real VoiceOver, native 200-percent zoom and 390-pixel mobile width. Accessibility rules and automated tests apply during each implementation step. After phase A and its acceptance, continue M9; billing/API pages and later public refinements retain their own dependencies. Optional realtime/custom-domain results and the existing UI.07 name/logo decision are not inferred or closed.

The earlier agent-only register contains **49 PASS, 0 FAIL, 18 BLOCKED and 5 NOT RUN** across 72 cases, including optional real-domain tests. Those counts are historical; the user's later completion confirmation supersedes its mandatory H/I/K blockers. See [the historical case register](BROWSER-RESULTS-2026-11-09.md) and [the current guide](BROWSER-TESTS.md). No new browser test or application change is implied by this handoff.

GiGa Team (2) retains its pre-existing Granted Pro override. `/status/status-test` is published with its original title/theme and no password/domain. PROVA-HTTP was recovered through a real HTTP 200 observation; the original threshold 3, EU and 60-second cadence remain. Synthetic role fixtures and cleanup are recorded in the case register; Free quota fixtures were removed. Recheck services before continuing. BT.08 changes client code and rebuilds the assets; fully reload existing browser tabs. No dependency, environment setting, migration, production privilege or 2FA change is required. [Failure-handling contract](REQUEST-FAILURES.md). BT.12 adds live React cadence validation to creation/editing: invalid input displays an inline error as it is typed and cannot trigger a form request or navigation. Create/Edit project the configured `maximumIntervalSeconds` alongside the existing plan minimum; backend validation remains authoritative. The assets are rebuilt; reload open tabs once to load the new page prop. No migration or configuration change.

BT.13 fixes K03/K04 navigation after 2FA setup: security fetches are marked as AJAX, and successful plan override mutations redirect to the explicit team detail instead of the session's previous URL. Reload open tabs once. If the old Inertia JSON modal is still open, directly reopen `/admin/teams/{TEAM}` and inspect the current override before continuing; the failed redirect happened after the mutation, so do not assume the save failed. The same explicit-destination rule applies to Fortify settings mutations so first-time enrollment works immediately after password reconfirmation. No migration, environment or user account-security change is required.

BT.14 replaces password redirects during plan override submission with inline confirmation on the team page. Reload open tabs once. After **Save override**, an expired confirmation shows **Current password** and **Confirm password and save override**; a valid password dispatches the requested change once, while wrong passwords/cancellation retain the draft without applying it. Choice and reason stay only in component memory, and the existing mutation middleware remains active. The new verified, no-store `/user/confirmed-password-status` endpoint reads only the session's server-defined timeout. No migration, environment change or user data repair is required.

The historical handoff below is preserved for context and superseded by this restart point.

## Browser acceptance handoff — 2026-11-06

The user ended browser acceptance at **H12**. Resume with **H13 — Status page updates and cache** in [BROWSER-TESTS.md](BROWSER-TESTS.md#h3-aggiornamento-e-cache), using GiGa Team (ID 2) and the actual public address `/status/status-test`. The status-page block remains in progress; optional realtime/domain cases and individual manual PASS results are not inferred from this progress. Continue acceptance before M9.01.

The guide was revised on 2026-11-08 for users who did not write the application. It now includes a screen map, browser/account contexts, explicit failure/recovery/publication procedures, exact public-state labels and per-block prerequisites. H13 distinguishes the component label from the overall sentence and measures its wait from the current opening or reopening. This is a documentation revision, not a new browser acceptance result; existing case identifiers and the H13 restart point are preserved.

BT.03 fixes native browser fetch context during live-page recovery. Client assets were rebuilt successfully with the complete frontend gate (175 DOM tests); already open pages need a full reload. The corrected coordinator also passed an isolated Firefox probe with synthetic same-origin HTTP responses. There is no migration, dependency, environment/key change or required server restart for this fix. See [REALTIME-CLIENT.md](REALTIME-CLIENT.md#native-browser-fetch-context-correction-2026-11-06) and [TASKS.md](TASKS.md#diagnosi-da-collaudo--aggiornamenti-realtime-2026-11-06).

At the last runtime checks, scheduling and Reverb were enabled and PROVA-HTTP was receiving current observations. Recheck running services and current entitlements on resume: GiGa Team's initial Pro trial has expired, so its 60-second monitors require a compatible plan/override. The session closure changes documentation only and does not stop or restart services, clean test data or modify the team's plan.

## Application structure

| Path | Responsibility |
|---|---|
| `artisan` | Load Composer and run a console request through Laravel. |
| `public/index.php` | Honor maintenance mode and dispatch incoming HTTP requests. |
| `bootstrap/app.php` | Register routes, liveness, Inertia/session revocation middleware and sensitive-field flash exclusions. |
| `bootstrap/providers.php` | Register the application's service provider. |
| `app/Providers/AppServiceProvider.php` | Extension point for later domain bindings and boot behavior. |
| `app/Http/Controllers/HomeController.php` | Select the public `Home` Inertia page. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share product name, a minimal current-identity projection and safe status; select the Blade shell. |
| `app/Models/User.php` | User identity, mandatory email verification, hidden encrypted 2FA fields and membership-based team resolution. |
| `database/factories/UserFactory.php` | Generate non-production synthetic user identities. |
| `database/seeders/DatabaseSeeder.php` | Explicit entrypoint for guarded, repeatable local/testing identity fixtures; see IDENTITY.md. |
| `database/migrations` | Baseline framework schemas plus incremental team, membership and current-team preference migrations. |
| `routes/web.php` | Public home route plus the explicitly declared authentication routes in routes/auth.php; monitoring remains future work. |
| `routes/console.php` | Reserved command and scheduling extension point for later milestones. |
| `resources/views/app.blade.php` | Accessible HTML document with Vite and Inertia directives. |
| `resources/js/app.tsx` | Discover React pages at build time and mount the Inertia client. |
| `resources/js/pages/Home.tsx` | Initial product identity and explicit development status, with no invented monitoring metrics. |
| `resources/js/vite-env.d.ts` | Supply Vite's `import.meta` and asset type declarations. |
| `resources/css/app.css` | Compile Tailwind utilities from project React/Blade sources. |
| `vite.config.ts` | Integrate the TSX entry point with Laravel, React and Tailwind. |
| `tests/Feature/BootstrapTest.php` | Verify initial HTML, JSON protocol, liveness and missing routes. |

## Host Vite and Docker dependencies

The host `node_modules` and Compose's `node-modules` volume are separate installations. Installing/building with `docker compose run --rm node ...` does not install packages for a host `npm run dev` process. If using host Vite, install from the current lock in the project directory:

```sh
npm ci --ignore-scripts --strict-peer-deps
npm run dev
```

After a lockfile change, stop the host Vite process, reinstall its locked dependencies and start it again. Laravel uses the development server while `public/hot` exists; a successful Docker build does not make a running host Vite installation complete. A `Failed to resolve import "laravel-echo"` overlay after login/navigation can therefore be an installation mismatch even when the package is correctly declared. Do not add a second dependency version to work around it. See [browser troubleshooting](BROWSER-TESTS.md#se-qualcosa-non-parte).

## Environment and service boundaries

Copy `.env.example` to `.env` once, then generate the local key with `php artisan key:generate`. Each environment variable has an English comment describing its purpose. The template contains no real credentials. The generated key stays in the ignored local `.env`; the static key in `phpunit.xml` is public test data.

PostgreSQL is the default database driver, and Predis is the selected Redis client. M0.02 does not create a database, run migrations, connect to Redis, configure Stripe, or start workers. File-based sessions/cache and synchronous jobs allow the initial page to boot before infrastructure exists. M0.03 must configure the development infrastructure and move cache/queues to Redis; M0.04 must configure the installed ecosystem packages. The synchronous queue is a temporary bootstrap choice, not the monitoring engine architecture.

Inertia loads `resources/views/app.blade.php` on the initial visit and returns JSON on subsequent visits with `X-Inertia: true`. The client echoes the initial page's asset version in `X-Inertia-Version`; a stale or missing version can legitimately trigger a 409 reload response after assets are built. Runtime and test page discovery both use `resources/js/pages` and `.tsx` files. Optional React SSR stays disabled; M8.06 supplies initial status-page metadata and essential public HTML through Blade, as assessed in [STATUS-PAGE-SEO.md](STATUS-PAGE-SEO.md).

`/up` is Laravel liveness only. It does not prove database, Redis or worker readiness; infrastructure health checks remain M11. Installed packages may register their own routes via discovery, but no team or token authorization feature is considered implemented.

## Composer manifest additions

These fields extend the baseline documented in DEPENDENCIES.md without changing dependency constraints or resolved versions.

| Field | Meaning |
|---|---|
| `autoload` | Production class-discovery configuration. |
| `autoload.psr-4` | Map PHP namespace prefixes to source directories. |
| `autoload.psr-4.App\` | Load application classes from `app/`. |
| `autoload.psr-4.Database\Factories\` | Load synthetic data factories from `database/factories/`. |
| `autoload.psr-4.Database\Seeders\` | Load explicit seeders from `database/seeders/`. |
| `autoload-dev` | Additional class loading for development and tests. |
| `autoload-dev.psr-4.Tests\` | Load test support classes from `tests/`. |
| `scripts` | Local commands and lifecycle hooks; no automatic database migration is configured. |
| `scripts.post-autoload-dump` | Clear stale framework metadata through ComposerScripts and discover package providers after autoload generation. |
| `scripts.dev` | Run the PHP development server on loopback; Vite runs separately with npm. |
| `scripts.test` | Execute the project Pest suite. |
| `scripts.format` | Apply the configured PHP-CS-Fixer rules. |
| `scripts.format:check` | Check PHP style with dry-run and show proposed changes without rewriting files. |
| `scripts.analyse` | Run PHPStan/Larastan using the project configuration and a 512 MB memory limit. |

## npm manifest additions

| Field | Meaning |
|---|---|
| `scripts` | Commands for the initial frontend toolchain. |
| `scripts.dev` | Run the Vite development server on loopback, alongside the PHP server. |
| `scripts.build` | Fail on TypeScript errors, then emit versioned production assets. |
| `scripts.types:check` | Check TypeScript without generating application assets. |

The existing package-lock graph remains valid because no dependency constraint changed. The build emits files to ignored `public/build`; the generated Vite manifest maps entry/module names to hashed JavaScript/CSS assets, imports and entry metadata. Vite owns this generated JSON; do not edit it manually.

## tsconfig.json reference

| Field | Meaning |
|---|---|
| `compilerOptions` | Shared compiler settings for browser source and Vite configuration. |
| `compilerOptions.target` | Compile against ES2022 language features. |
| `compilerOptions.lib` | Include ES2022 and browser DOM/iterable type declarations. |
| `compilerOptions.module` | Preserve modern ECMAScript module imports for Vite. |
| `compilerOptions.moduleResolution` | Resolve packages using bundler-aware semantics. |
| `compilerOptions.jsx` | Use React's automatic JSX runtime. |
| `compilerOptions.strict` | Enable TypeScript's strict type-checking family. |
| `compilerOptions.noEmit` | Leave asset emission to Vite. |
| `compilerOptions.isolatedModules` | Require files to support independent transformation. |
| `compilerOptions.esModuleInterop` | Support interoperable package import syntax. |
| `compilerOptions.skipLibCheck` | Skip checking dependency declaration internals while checking application usage. |
| `compilerOptions.forceConsistentCasingInFileNames` | Reject imports whose casing disagrees with their paths. |
| `compilerOptions.noUncheckedIndexedAccess` | Require handling absent entries in maps, including dynamic page loaders. |
| `compilerOptions.resolveJsonModule` | Allow typed JSON imports when needed. |
| `compilerOptions.types` | Include Vite client and Node declarations. |
| `include` | Restrict checking to application TypeScript/TSX and `vite.config.ts`. |

## Quality and test configuration

PHP-CS-Fixer scans application-owned PHP, migrations, routes, tests and entry points. It excludes dependency/generated cache directories, uses PSR-12 plus explicit short-array/import/string rules, preserves explanatory comments and disables risky rules. Its generated cache is ignored. On local PHP 8.5 it warns that the project minimum is PHP 8.4; real PHP 8.4 execution remains required in Docker/CI.

Larastan runs at level 8 on application classes, bootstrap, database helpers/migrations and routes. Its local cache is ignored. This baseline does not complete M0.05: frontend lint/format tooling and the Rector evaluation remain open.

`phpunit.xml` selects HTTP feature tests, loads Composer, limits source coverage discovery to `app/`, and forces test environment settings. Test cache/session/mail use memory, the queue is synchronous, logging is discarded, and PostgreSQL remains the selected database driver. These four bootstrap tests do not access a database; PostgreSQL integration, unit suites and frontend interaction tests are M0.06 and feature work. No SQLite fallback has been introduced.

The English comments in source document files, classes, methods, callbacks, props, parameters and variables. JSON fields are documented here or in DEPENDENCIES.md because JSON does not accept comments. `.editorconfig` standardizes encoding, newlines and indentation; `.gitattributes` retains the upstream text normalization rules.

## Local execution and verification

Follow the commands in [README.md](../README.md). Git is initialized on `main`; no commit or remote was created. Environment files, dependencies, build output, logs and quality-tool caches remain ignored.

Verified on local PHP 8.5.9 and Node 22.23.2:

- Composer install from the existing lock, autoload generation, package discovery and Artisan environment boot.
- Four Pest feature tests, with 26 assertions.
- TypeScript strict checking and Vite production compilation.
- Larastan level 8 without errors.
- PHP-CS-Fixer formatting and subsequent dry-run.
- Real HTTP responses for the initial document, versioned Inertia JSON and `/up`; all three compiled JavaScript/CSS assets served successfully. The temporary server was stopped after the check.

No real browser execution, frontend interaction suite, PostgreSQL/Redis test, migration execution, PHP 8.4 runtime check or GitHub Actions run is claimed. Those checks remain in their owning milestones.

## M0.03 infrastructure follow-up

[DOCKER.md](DOCKER.md) documents the Compose runtime, internal database/Redis addresses, process environment overrides, explicit migration/startup commands and opt-in real-service tests. Host bootstrap defaults remain file/sync; Compose selects Redis sessions/cache/queues. Historical M0.02 verification above describes the state at that milestone, not the current Git remote or infrastructure state.

## M0.04 service integration follow-up

[SERVICES.md](SERVICES.md) documents Horizon supervisors and operator access, Reverb origins and signed internal publishing, Sanctum token storage/abilities, fixed database Scout search and test-only Cashier credentials. Package billing routes are disabled and team billing migrations remain M9. The new token migration is explicit; historical M0.02/M0.03 notes above describe those milestones at their completion.

## M0.05 quality follow-up

[QUALITY.md](QUALITY.md) documents ESLint 10/type-aware React checks, Prettier scope, additional strict TypeScript fields, the five quality-tool regression tests, and the decision to evaluate but not install Rector without a concrete refactoring target. Node 22 now requires at least 22.13.0 for ESLint 10; PHP-CS-Fixer and Larastan remain the existing PHP baseline. New package.json fields and resolved dependencies are documented in [DEPENDENCIES.md](DEPENDENCIES.md).

## M0.06 testing follow-up

[TESTING.md](TESTING.md) documents Unit/Feature/Integration suites, guarded ephemeral PostgreSQL/Redis services, User factory coverage, Vitest/Testing Library and the executable full-stack verification command. Ordinary Pest runs skip integration without connecting; full verification uses `php-test` and refreshes only its dedicated schema. New JSON scripts, Node patch requirements and test dependencies are documented in [DEPENDENCIES.md](DEPENDENCIES.md).

## M0 final clean-checkout verification

On 2026-10-19 the user created a fresh checkout at `/private/tmp/signalbox-m0-clean`. It initially had no environment file, vendor directory, frontend dependencies, build output or cached application configuration. The documented Docker bootstrap installed 157 Composer packages and 292 npm packages from the locks, created a fresh application database and ran all four baseline migrations. Image layers reused the Docker build cache; this was not a cache-free image rebuild.

The isolated Compose project `signalbox-m0-clean` passed `./scripts/verify.sh`: PHP 8.4.26, formatter dry-run on 49 files, Larastan level 8, 46 Pest tests with 125 assertions and no skips, frontend lint/format/types, five quality tests, three DOM tests and production build. The actual Docker frontend runtime was Node 22.23.3/npm 10.9.9, within the manifest constraints. HTTP home/liveness/assets, versioned Inertia JSON, anonymous Horizon denial, Horizon/scheduler processes and Reverb WebSocket upgrade passed. See [TASKS.md](TASKS.md) for commands, evidence and limitations.

This completes M0 together with the previously user-confirmed PHP 8.4/8.5 CI results; no new remote CI run or local PHP 8.5 integration run is claimed. Application source, configuration, manifests and locks matched the working project across 70 compared files; the checkout's older committed Markdown did not affect runtime verification. At M0 closure the next task was M1.01, now implemented as described below.

## M1.01 identity foundation

[IDENTITY.md](IDENTITY.md) documents the new Team/Membership models, role/plan enums, transactional creation action, safe current-team resolver, incremental migrations, factories and explicit synthetic local/testing seeder. Authentication UI, invitations, switching, tenant context and policies remain subsequent M1 tasks. Existing users are preserved without automatic team assignment. No dependency, manifest or lockfile changed.

## M1.02 web authentication

[AUTHENTICATION.md](AUTHENTICATION.md) documents implemented registration, login/logout, password recovery, mandatory email verification and optional 2FA with English Inertia pages. Registration creates the initial team atomically; existing users remain intact. One incremental migration adds nullable encrypted-secret storage and case-insensitive email uniqueness. Apply migrations deliberately to the intended local environment; automatic checks continue to use only the isolated test database. Fortify and its lock changes, including the targeted HttpFoundation advisory fix, are documented in [DEPENDENCIES.md](DEPENDENCIES.md). Invitations/switching are now documented in [TEAMS.md](TEAMS.md); general tenant policies remain M1.04.

## M1.03 invitations and membership management

[TEAMS.md](TEAMS.md) documents the incremental invitation migration, explicit team routes, membership actions and Inertia pages. Apply the new migration deliberately to development storage; automated verification continues to target only isolated PostgreSQL. No dependencies or lockfiles change. Ordinary registration still creates a first team; invited registration creates only an unverified identity until explicit acceptance. TeamScope/context and general policies remain M1.04.

## M2.01 monitor persistence

[MONITORS.md](MONITORS.md) documents the new incremental monitors migration, DTOs and encrypted network fields. Apply the migration deliberately to the intended local database; automated verification uses isolated PostgreSQL only. There is no new route, checker, scheduled execution, seed data or dependency in this task. Existing application keys remain unchanged.

## Monitor input boundary (M2.02)

[MONITOR-INPUT.md](MONITOR-INPUT.md) describes the new `config/monitor-input.php` operational bounds and the narrowly scoped global string-middleware exceptions. No environment variables, dependencies, migration, production routes or process changes are required. The Form Requests deny without future monitor policies; test routes and Gate grants exist only in the isolated test application. SSRF protection remains M2.03 before any outbound monitor I/O.

M2.03 adds `config/outbound-security.php` and the optional `OUTBOUND_ALLOWED_CIDRS` variable (comma-separated CIDR exceptions, empty by default and documented in `.env.example`), and taps `App\Logging\RedactSecrets` into every concrete log channel. No dependency, migration, route or process change is required. See [OUTBOUND-SECURITY.md](OUTBOUND-SECURITY.md).

M2.04 adds `config/plans.php` (plan entitlements) and `routes/monitors.php`, included by `routes/web.php`. No environment variable, dependency, migration or process change is required. See [MONITOR-CRUD.md](MONITOR-CRUD.md).

M2.05 adds monitor page routes to `routes/monitors.php` and makes `App\Models\Monitor` Scout-searchable with the existing database driver. No environment variable, dependency, migration, index rebuild or process change is required. See [MONITOR-UI.md](MONITOR-UI.md).

M2.06 adds tests only. The concurrency suite starts its own short-lived `artisan serve` inside `php-test` against the isolated test database; it does not use the development server on port 8000. No environment variable, dependency, migration or process change is required. See [TESTING.md](TESTING.md).

MA adds three migrations: `users.is_platform_admin`, `platform_audit_events` and `team_plan_overrides`. It also adds the `signalbox:platform-admin` command and `routes/admin.php`. `HORIZON_ADMIN_EMAILS` is removed: Horizon now requires a platform operator. After applying the migrations, grant the first operator with `php artisan signalbox:platform-admin <email>`. No new variable or dependency is required. See [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md).


## Recoverable scheduling foundation (M3.01–M3.02)

[MONITOR-SCHEDULING.md](MONITOR-SCHEDULING.md) documents the execution outbox migration, monitor execution generation, three scheduling commands and `config/monitor-scheduling.php`. `MONITOR_SCHEDULING_ENABLED` defaults to false; keep it disabled until the real consumer/runner is integrated and the migration is deliberately applied to the intended database. Automated checks apply it only to the isolated test database. No dependency, lockfile, development key or process change is required.


## HTTP and keyword acquisition (M3.03)

[HTTP-CHECKERS.md](HTTP-CHECKERS.md) documents the typed checker boundary and `config/monitor-checks.php` (10 observations per batch, 1 MiB decoded response bytes per observation across redirects). The provider binds the monotonic check clock; the application consumer remains unbound and scheduling disabled. Guarded HTTP connections now forbid reuse so each hop honours its freshly authorized pin. No new environment variable, migration, dependency or process change is required.


## TLS/DNS acquisition and bounded resolution (M3.04)

[TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md) documents the shared concurrent TLS/DNS coordinator, replaceable transports, verified certificate expiry and exact DNS comparison. `monitor-checks.resolution_timeout_ms` defaults to 1000 ms and bounds the complete A/AAAA resolution used by the HTTP guard. OpenSSL and the existing locked ReactPHP components become explicit direct requirements; no package version changes. Scheduling remains disabled and the consumer unbound. No migration, environment variable, development-key or process change is required.


## Heartbeat ingress and lifecycle (M3.05)

[HEARTBEATS.md](HEARTBEATS.md) documents the incremental `2026_10_26_000011_create_monitor_heartbeats.php` migration, bearer-only `POST /heartbeats`, authenticated credential mutations, receipt history and pure slot evaluation. Apply the migration deliberately before using the updated monitor lifecycle; automated checks apply it only to isolated PostgreSQL. Existing heartbeat monitors arm at migration time without a credential. `config/heartbeat.php` contains mandatory IP/credential traffic limits. No dependency, environment variable, key or application-process change is required. The consumer remains unbound and scheduling disabled.


## Regional runner and results (M3.06–M3.07)

[MONITOR-RUNNER.md](MONITOR-RUNNER.md) supersedes the earlier unbound-consumer notes: both consumer contracts now resolve to the real runner. Apply `2026_10_26_000012_create_partitioned_check_results.php` deliberately to the intended database after the heartbeat migration. Automated checks migrate only isolated PostgreSQL. It adds the immutable monthly result parent, current/next-two partitions, broker attempt fence and monitor watermark. `monitors:partitions` prepares months daily and on demand; it never drops retained data. Regional job/Horizon timeout is 45 s, deadline 50 s, cache locks 55 s and Redis retry_after remains 90 s. The regional batch limit is two. No dependency/lockfile, environment variable, development key or process change is required. Keep `MONITOR_SCHEDULING_ENABLED=false` until operational activation is deliberately chosen.

[INCIDENTS.md](INCIDENTS.md) documents the incremental `2026_10_28_000013_create_incidents_tables.php` migration (incidents, append-only timeline, single-open-incident index and lifecycle constraints) and `config/incidents.php` (`reopen_window_seconds`, default 900). Apply the migration deliberately to the intended database after the result migration; automated checks migrate only isolated PostgreSQL. No dependency, environment variable, key or process change is required.

`2026_10_28_000014_create_monitor_region_sequences.php` adds the per-region failure sequences used by incident detection ([INCIDENTS.md](INCIDENTS.md#regional-confirmation)). Apply it deliberately after the incident migration. Once applied, every recorded check result also updates sequences and may open, resolve or reopen incidents; no dependency, environment variable or process change is required.

`2026_10_28_000015_create_maintenance_windows.php` and `2026_10_28_000016_create_monitor_pause_periods.php` add maintenance windows and the pause history trigger ([MAINTENANCE.md](MAINTENANCE.md)). Apply them deliberately after the regional sequence migration. Monitors already paused or suspended receive an open period starting at the migration instant. No dependency, environment variable or process change is required.

M4.05 adds `routes/incidents.php`, included by `routes/web.php`, with the incident pages, incident changes and monitor maintenance routes. No migration, environment variable, dependency or process change is required; the incident and maintenance migrations of M4.01–M4.04 must already be applied. See [INCIDENT-UI.md](INCIDENT-UI.md).

M5.01 adds `2026_10_29_000017_create_notification_tables.php` (channels, escalation policies, delivery logs) and `2026_10_29_000018_add_escalation_policy_to_monitors.php` (optional per-monitor override). Apply them deliberately, in this order, after the M4 migrations. It also adds `config/notifications.php` (operational bounds, no environment variable), `routes/notifications.php` included by `routes/web.php`, and adds `config` to the global `dontFlash` list. Until the M5.02 adapters exist, verification messages are refused by `UnavailableChannelVerificationSender`. No dependency or process change is required. See [NOTIFICATIONS.md](NOTIFICATIONS.md).

M5.02 supersedes that unavailable-sender note: `ChannelVerificationSender` now resolves to the adapter-backed sender for email, Slack, Telegram, signed webhook and the explicit SMS recording stub. No migration, dependency, environment-file or application-key change is introduced; the user confirmed the preceding migrations applied to development storage on 2026-10-29. `notifications.http` adds bounded transfer/connection timeouts and decoded response bytes. Channel email explicitly uses the existing `smtp` mailer, whose socket timeout is now five seconds, so a default `MAIL_MAILER=log` cannot write codes or message content into logs. Configure the existing SMTP settings to receive verification email; Mailtrap Email Sandbox captures it in its sandbox inbox. SMS cannot verify or activate a destination. See [NOTIFICATIONS.md](NOTIFICATIONS.md#provider-adapters-m502) for the signed webhook contract and operational limits.


## Incident delivery outbox (M5.03)

[NOTIFICATION-DELIVERY.md](NOTIFICATION-DELIVERY.md) documents `2026_10_29_000019_add_notification_delivery_outbox.php`, which adds the processed-event marker and encrypted message/lease metadata to delivery logs. Apply it deliberately after the channel/monitor-override migrations before using incident transitions with this source version. No environment variable, key, dependency or lockfile changes. Tests migrate only isolated PostgreSQL.

Incident transitions now prepare notification intents transactionally; public after-commit events publish scalar Redis jobs and `notifications:dispatch` repairs due work every ten seconds. `config/notifications.php` defines three attempts with 30/120 s backoff and a 5 s coalescing window. Horizon adds the independent `notifications` supervisor (60 s timeout), the ledger lease is 75 s and Redis retry_after remains 90 s. Compose queue shutdown grace is now 80 s. After deliberately migrating, recreate queue/scheduler with `docker compose up -d --no-deps --force-recreate queue scheduler`; no application processes are restarted by verification. Monitoring scheduling remains separately controlled. M5.04 adds escalation/maintenance as described below; quiet hours remain M5.05.

## Escalation and maintenance admission (M5.04)

[NOTIFICATION-DELIVERY.md](NOTIFICATION-DELIVERY.md) now documents all frozen escalation steps, absolute delays from confirmation/reopening, recovery cancellation and maintenance silencing on every attempt/retry. No new migration, dependency, environment variable or key is introduced. Existing M5.03 cycles keep their original first-step-only audience; new openings/reopenings prepare all steps. The outbox migration above remains required if not yet applied. Reload long-lived queue/scheduler code deliberately with `docker compose up -d --no-deps --force-recreate queue scheduler`; verification does not restart development processes. Quiet hours remain M5.05.

## Daily channel quiet hours (M5.05)

[QUIET-HOURS.md](QUIET-HOURS.md) documents the incremental `2026_10_30_000020_add_channel_quiet_hours.php` migration, explicit IANA local times and DST semantics. Apply it deliberately after the outbox migration before configuring silencing. Existing channels have no interval by default. The owner/admin `PATCH …/notification-channels/{channel}/quiet-hours` route edits silencing independently of credentials and verification; the notification page includes its editor. The ledger checks the current interval before each attempt/retry. Reload long-lived queue/scheduler code after migrating. Verification migrates only isolated PostgreSQL and never restarts development processes. No dependency, lockfile, environment variable or key changes.

## Notification acceptance matrix (M5.06)

### Quiet-hours delivery correction (2026-11-05)

Rebuild the editor assets, stop the old queue/scheduler workers, apply `2026_11_05_000025_defer_quiet_hours_notifications.php`, then recreate web/queue/scheduler with the revised code. Quiet-hour waits now remain pending until the first permitted UTC instant and resume automatically, grouping due steps per incident and recipient. Recovery cancels obsolete openings. The migration also restores only quiet-suppressed alerts for still-open current cycles on active verified channels; applying it can resume notifications about ongoing incidents. Original UUIDs, frozen audiences and attempt counts remain intact. No dependency, environment variable, key or new process is introduced. Tests use only isolated storage and fake providers; no development migration or worker restart is performed. See [QUIET-HOURS.md](QUIET-HOURS.md#operations-and-verification) for the adoption commands.

### Original acceptance checks

[NOTIFICATION-ACCEPTANCE.md](NOTIFICATION-ACCEPTANCE.md) maps notification exit criteria to the automated suites. M5.06 adds tests/documentation and raises Composer's finite child-process timeout from 300 to 900 seconds after the full PostgreSQL suite exceeded the default. No migration, dependency, environment variable, application key, queue timeout or application-process change. Existing channel/outbox/quiet-hour migrations remain prerequisites for their application features. Full verification still uses isolated test storage and fake recipient services; no development migration or worker restart is performed.

## Committed private team broadcasts (M6.01)

[BROADCASTING.md](BROADCASTING.md) documents the new result event, after-commit listener and scalar `TeamUpdate` frame. Horizon adds a dedicated Redis `broadcasts` supervisor with three transport attempts and a 10-second timeout. No migration, dependency, lockfile, environment variable or key change. Recreate queue/scheduler deliberately to load the new code/supervisor; verification touches only test services. Null broadcasting disables publication. Echo/frontend reconciliation remains M6.03.

## Private subscription authorization (M6.02)

`POST /broadcasting/auth` uses verified web sessions, real CSRF protection and current membership for every team role. It locally signs only canonical private-team channels using the existing Reverb credentials, even when publication uses null/log. Incomplete credentials fail with 503 without affecting unrelated bootstrap paths. No dependency, environment, migration or worker change is needed for this endpoint. [BROADCASTING.md](BROADCASTING.md#subscription-authorization-m602) documents inputs, denials and the protocol limitation: membership revocation denies new grants but does not forcibly evict an already subscribed socket.

## Browser live-page recovery (M6.03)

[REALTIME-CLIENT.md](REALTIME-CLIENT.md) documents native Echo/Pusher, the React hook on monitor/incident list/detail pages, event-driven partial reads, reconnect recovery and lifecycle cleanup. Install the new npm lock (`docker compose run --rm node npm ci --ignore-scripts --strict-peer-deps`) and rebuild assets through the usual workflow. Optional `REVERB_BROWSER_HOST`, `REVERB_BROWSER_PORT` and `REVERB_BROWSER_SCHEME` are separate from Compose's internal publishing coordinates. The server projects only public coordinates for verified sessions with the `reverb` publishing driver; log/null or incomplete credentials leave pages usable without sockets. Match the public port and TLS scheme to the browser endpoint. No migration, application key, server queue or development-process change is performed by verification. DEPENDENCIES records the two new exact runtime dependencies and targeted pre-existing undici test-dependency fix.

## Operational team dashboard (M6.04)

[DASHBOARD.md](DASHBOARD.md) documents the new private `/teams/{team}/dashboard` route, bounded operational lists/counts, hourly regional raw-latency chart and existing Echo/partial recovery. Rebuild assets through the normal workflow; no dependency, migration, environment variable, key or worker configuration changes. The frontend execution-ID validation is corrected to match the numeric backend ledger, with a shared PHP/TypeScript fixture. Uptime/coverage/SLA production values came later, from M7.03. Automated checks use isolated PostgreSQL and simulated browser transports without touching development data/processes.

## Realtime acceptance and reconnect grant cleanup (M6.05)

[REALTIME-ACCEPTANCE.md](REALTIME-ACCEPTANCE.md) documents native Reverb protocol and committed-database acceptance plus native-Echo DOM tests. The demonstrated reconnect race is fixed by aborting/fencing authorizations tied to an old socket, including late JSON completion. Adopt it through the normal asset rebuild; there is no new dependency, migration, environment/key or worker configuration. Verification uses only dedicated test storage and in-memory transports. Final uptime/SLA integration came later, with M7.03.

## Session handoff — 2026-10-30

The restart point of this handoff was **M7.01**, now complete; later tasks are tracked in [TASKS.md](TASKS.md). [TASKS.md](TASKS.md#chiusura-di-sessione-e-ripartenza--2026-10-30) records the session closure, latest successful local gate, pending operational confirmations and required references. M6.04 and M6.05 are complete.

This handoff changes Markdown only and does not migrate development storage, install packages, activate monitoring or restart application processes. Earlier outbox/quiet-hour migrations and the broadcast supervisor remain prerequisites for their respective features; their development deployment is not confirmed by the automated test gate. Follow the relevant sections above for any changes not already applied. Read ADR-008/ADR-009 before designing rollup storage, retention or availability calculations.

## Check rollups (M7.01)

Apply `2026_10_30_000021_create_check_rollups.php` deliberately to the intended database. `metrics:rollups` is available but not scheduled until M7.04, so nothing changes in running processes. No dependency, environment variable or key is added. See [METRIC-ROLLUPS.md](METRIC-ROLLUPS.md).

M7.02 adds the read-only `metrics:rollup-storage` command; it needs no migration, dependency, environment variable or process change.

[METRIC-ROLLUPS.md](METRIC-ROLLUPS.md#sla-service-m703) documents the time-weighted SLA service, now also used by the dashboard. Adopt it through the normal asset rebuild: there is no new dependency, migration, environment variable, key or worker. The dashboard already reads only raw results; older windows answer from rollups only once the rollup migration is applied and `metrics:rollups` has run.

`metrics:nightly` is now scheduled daily at 02:00 UTC and removes expired data: raw result months, ledger rows, heartbeat receipts and rollups beyond plan history. It starts as soon as the scheduler loads the new code, so apply `2026_10_30_000021_create_check_rollups.php` first; without that table the rollup and partition steps fail and no partition is dropped. There is no new migration, dependency, environment variable or key. See [METRIC-ROLLUPS.md](METRIC-ROLLUPS.md#nightly-maintenance-m704).

The monthly PDF report adds the Composer dependency `dompdf/dompdf`: run `composer install` and rebuild the assets. There is no new migration, environment variable, key or worker. See [METRIC-ROLLUPS.md](METRIC-ROLLUPS.md#monthly-pdf-report-m705).

M8.01 adds the migration `2026_11_01_000022_create_status_pages.php` and `routes/status-pages.php`, included by `routes/web.php`. Run `php artisan migrate` and rebuild the assets. There is no new dependency, environment variable or worker. M8.03 uses the application cache already configured; pages keep working without it. See [STATUS-PAGES.md](STATUS-PAGES.md).

M8.04 adds `2026_11_02_000023_create_status_page_subscriptions.php`, encrypted subscriber/outbox tables and a tenant-bound key for incident entries. Apply the migration deliberately, rebuild assets and reload queue/scheduler code before using email subscriptions. The existing `notifications` supervisor handles the scalar jobs recovered by `status-pages:dispatch-emails` every ten seconds. Configure existing SMTP settings and the externally reachable `APP_URL`; no dependency, lockfile, new variable or supervisor changes. Tests use only isolated storage and fake mail. Consent, password changes, revocation and delivery limits are documented in [STATUS-PAGES.md](STATUS-PAGES.md#email-subscriptions).

M8.05 adds `2026_11_02_000024_create_status_domain_bindings.php`, pending page claims and an exclusive expiring hostname registry. Apply it deliberately and reload long-lived code; the scheduler rechecks bounded DNS batches every five minutes. `GuardStatusPageHosts` now rejects unknown origins before sessions and isolates verified custom origins from account routes/state. `APP_URL` is the primary allowed hostname; local/testing additionally accept explicit loopback names. `config/status-domains.php` documents lease/deadline bounds and the optional `TRUSTED_PROXY_IPS`/`STATUS_DOMAIN_TLS_PEERS` variables in `.env.example`. Both peer lists are explicit; forwarded Host is never trusted. [STATUS-DOMAINS.md](STATUS-DOMAINS.md) defines custom session cookies, the private TLS admission endpoint and the Caddy deployment template. No dependency/lockfile, development migration, public TLS service or certificate is added by verification.

M8.06 adds live status-page metadata, HTTP crawler headers and a Blade public summary outside the Inertia root. Deploy the PHP/views and rebuild normal client assets; no migration, dependency, variable, worker or SSR process is added. The canonical always uses the configured primary `APP_URL`, including from verified aliases. Password pages, even unlocked, and mailbox capabilities remain generic and noindex. See [STATUS-PAGE-SEO.md](STATUS-PAGE-SEO.md).

`scripts/verify-dates.sh` needs network access, because it installs libfaketime in a disposable container. See [TESTING.md](TESTING.md#fixed-dates-and-result-partitions).
