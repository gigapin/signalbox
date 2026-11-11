# Operator workflows and member plan visibility

UI.04.4 integrates the existing operator directory/detail, override confirmation and audit history with
the private application UI. [TASKS.md](TASKS.md) owns actual verification and acceptance limits. Existing
operator rules and override semantics remain in [PLATFORM-ADMIN.md](PLATFORM-ADMIN.md).

## Separate authority and workspace

Platform administration remains outside team context. Inspecting a team never selects that team,
creates membership or grants access to its tenant routes. Operators still require the persisted flag,
verified email and production second factor on the server; team owner/admin/member/viewer roles never
grant platform authority. Every override action rechecks its gate under the Team lock, and requires a
recent password. No new operator-promotion, impersonation, billing or payment endpoint is introduced.

PrivateContextLayout now requires an explicit operator flag to render platform children, in addition to
its existing denial/logout boundary. Missing/false flags hide inspected data, title, form, audit and
operator navigation; account context remains separate. Page-owned hooks are below this removable
boundary. DetailContent is keyed by the inspected team ID, so state-preserving navigation cannot carry
a captured override or password prompt into another team. The account key also disposes intent when
the operator identity changes. Server authorization remains authoritative on every request; no polling
or new push-revocation channel is introduced.

## Directory and return navigation

The directory uses DataTable with a visible caption, native row/column headers and keyboard-scroll
viewport. Search reuses useReadNavigation: a pending read freezes the input, duplicate submissions are
suppressed, failure retains the draft/previous rows, and retry is explicit. Accepted search/history
replacements restore the accepted selection. Empty platform, no search matches and out-of-range pages
are distinct. Pagination uses previous/next/first-page links rather than allocating every page number.

PlatformTeamSelection is shared by direct GET parsing and return hints: trimmed literal q has at most
100 characters and no control characters; page is a positive one-based integer with at most nine digits.
Invalid controls are dropped and reported by name. Existing literal PostgreSQL matching against team
name, slug or owner email and the fixed 25-row page remain unchanged.

Directory links carry the accepted search/page. PlatformDirectoryReturn reuses ListReturn's bounded
same-path parser for /admin/teams only: external, tenant, detail, oversized, control, array and duplicate
hints cannot become destinations. A mutation's body cannot supply navigation. Accepted PUT/DELETE
redirects return to the actual route team's detail with normalized directory context, while requests
without a hint retain their existing destination. Original-request URLs remain protocol metadata;
assertions target reconstructed link props and redirects rather than deleting user input from metadata.

## Override intent and password confirmation

The existing native confirmation precedes a same-origin no-store AJAX read of password-confirmation
status. It neither extends the password window nor authorizes an operator. A current confirmation sends
one captured draft; an expired confirmation opens the existing inline password challenge without losing
choice, inclusive UTC expiry date or mandatory audit reason. Cancel forgets the pending operation and
password, retains the editable draft and restores the Save trigger focus. An aborted/late status or
password response cannot submit an old operation. 401/403/404 remove private platform content both in
the preflight and password challenge. Network/malformed responses retain a safe error without raw data.

The actual override write now uses useOwnedMutation/MutationFeedback. Pending inputs are frozen;
refused or uncertain results retain the draft and do not clear the reason. An uncertain result requires
saved-state review before another manual attempt; reconnect alone never retries. Confirmed acceptance
clears the reason. Pristine override choices follow a changed saved override, while dirty/pending intent
remains local. The server still validates the inclusive UTC date, coherence and future exclusive end,
serializes against quota writes, writes an audit event atomically and suppresses identical effects.
Expiry between preflight and mutation is still governed by the actual server password gate; the
preflight is not a substitute for that gate or a promise that a later write will be admitted.

## Audit history

The existing latest-50 bound and newest-first server order are preserved. History now presents Before
and After from the documented override snapshot fields only: kind, granted plan and expiry. Null means
No override; missing/unsupported snapshots say Not recorded. Arbitrary nested change keys are never
stringified or rendered. Actor absence means command line or deleted account; no operator identity is
invented. Reasons and UTC timestamps remain explicit operator-only data. Historical snapshots do not
claim that the override is currently active; the effective entitlement remains the source of limits.

## Ordinary team plan and quotas

TeamController::show adds a lazy planSummary only after current membership and TeamPolicy admission.
TeamPlanSummary asserts the matching TenantContext, resolves TeamEntitlement at one UTC instant and
reads limits from PlanLimits. Its explicit projection contains stored/effective plan and source,
exclusive entitlement expiry, recorded initial trial state/start/end, monitor and status-page usage,
minimum interval, rollup history and snapshot time. It exposes no override reason, granting operator,
audit, billing identity, payment amount or renewal date.

Monitor and status-page counts carry both TeamScope and explicit team predicates. Paused and
plan-suspended monitors count; draft status pages count. The read service executes three queries at
one and forty monitors (override resolution and two counts), verified on isolated PostgreSQL. These are
service query counts, excluding membership/controller/shared navigation work, and not latency or
production load measurements. Partial Inertia reads that omit planSummary do not execute that work.
The snapshot is advisory; mutation actions recheck limits under locks.

Plan and usage appears inside ordinary Team settings for every current role, without an Admin,
checkout, upgrade or payment action. Missing old-history data asks for reload instead of claiming Free
or zero usage. Above-quota counts stay visible as recorded; Internal/testing limits remain finite.
Recorded trial dates survive expiration and can coexist with another granting rule. The stored plan
source is labelled Stored plan and does not claim a verified Stripe subscription/payment before M9.
Rollup history is distinct from raw-result retention. No quota suspension/reconciliation behavior is
added ahead of M9.05; PDF, portal and later billing/API scope remain intact.

## Adoption and checks

No route, migration, dependency, environment value or worker change is required. Rebuild/reload normal
frontend assets. New PHP classes use container autowiring. PlatformWorkflowTest.php covers actual
operator redirects/return hints, bounded parsing, all member roles with fresh/foreign/revoked access,
precedence/exclusive ends, quota counts, privacy and constant query growth. PlatformWorkflows.test.tsx
uses actual Inertia pages for directory reads/history, bounded navigation, mutation outcomes, disposed
intent, password/access loss, dirty/pristine baselines, allowlisted audit and ordinary plan presentation.
Existing operator, override, tenant and UI suites remain regressions. Exact outcomes and corrections
are in TASKS; real browser, VoiceOver, native zoom, real sessions and remote CI need separate evidence.
UI.06 retains final acceptance, and UI.04.5 waits for M9–M10.
