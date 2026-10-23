# Monitor pages, search and filters

M2.05 adds the browser interface on top of the [model](MONITORS.md), the [input contract](MONITOR-INPUT.md), the [outbound boundary](OUTBOUND-SECURITY.md) and the [mutations and plan limits](MONITOR-CRUD.md). M2.06 adds the [integrated coverage](#integrated-coverage-m206), including concurrent attempts to add a sixth monitor through the UI. Evidence is in [TASKS.md](TASKS.md).

## Pages and routes

| Route | URL | Page | Roles |
|---|---|---|---|
| teams.monitors.index | GET /teams/{team}/monitors | Monitors/Index | owner, admin, member, viewer |
| teams.monitors.create | GET /teams/{team}/monitors/create | Monitors/Create | owner, admin, member |
| teams.monitors.show | GET /teams/{team}/monitors/{monitor} | Monitors/Show | owner, admin, member, viewer |
| teams.monitors.edit | GET /teams/{team}/monitors/{monitor}/edit | Monitors/Edit | owner, admin, member |

The pages share the mutation group's `auth`, `verified` and `EstablishTeamContext` middleware. They also use `NoStoreAuthentication`, so responses carrying private configuration projections are sent with `Cache-Control: no-store, private`. `MonitorPageController` handles reads and `MonitorController` keeps the mutations. Every page authorizes through `MonitorPolicy` before it loads private data, and another team's monitor is a 404.

User choice: after a creation the browser lands on the new monitor's detail page, and after a deletion on the list. Update, pause and resume still redirect back. Status flashes map to English messages in `HandleInertiaRequests`.

## Projections

`MonitorPresenter` builds every projection. No model is serialized.

- **Summary (list rows).** Built from non-configuration columns only: id, name, type, observed status, operational state, interval, regions and last check. Listing therefore never decrypts URLs, headers or bodies.
- **Detail (show and edit).** Adds timeout, threshold, next check, creation time, quota position (`withinQuota`) and `configuration`. The configuration comes exclusively from `MonitorConfigurationProjection`:
  - a redacted URL, with query values shown as `[REDACTED]` and no userinfo or fragment;
  - header names only;
  - the body's presence and size in bytes;
  - non-secret TLS, DNS and heartbeat settings.

  Its keys keep the input field names so edit forms can prefill the non-secret values directly.
- **Shared page props.** Pagination counters, enum `options` and `usage` (used/limit/minimum interval/full). The client builds page links from its own filters, which keeps Scout's `query` parameter (the escaped engine string) out of URLs.

Quota and permission props (`usage`, `can.*`, `withinQuota`) are hints only. Following ADR-004, `ManageMonitors` re-checks the policy and the plan limits under the Team lock on every mutation. React copies no plan numbers.

## Search

`Monitor` uses Scout's `Searchable` with the database driver. `toSearchableArray()` declares `name`, `tls_host` and `dns_name`, and the engine turns each key into an `ilike` predicate on the column of the same name.

User choice: URL, header and body columns hold ciphertext, so SQL cannot match them and they must not be decrypted for search. HTTP and keyword monitors are therefore found by name only, and adding a derived plaintext host column would need its own decision.

Search text is trimmed and limited to 100 characters. `MonitorListing::likeLiteral()` escapes `\`, `%` and `_`, using PostgreSQL's default backslash escape, so they match literally.

Tenant isolation does not rely on one mechanism alone:
- the engine builds its query from `Monitor::newQuery()`, so `TeamScope` applies;
- `MonitorListing` also adds the explicit `team_id` predicate that ADR-003 requires for search.

A test checks the explicit predicate by running the listing in team A's context for team B and expecting no rows.

## Filters, ordering and pagination

User choice: filters cover type, observed status (`up`/`down`/`unknown`) and operational state. There is no region filter. All filters combine conjunctively with the search text.

`MonitorOperationalState` derives the operational state from the two independent flags:

| `is_plan_suspended` | `is_paused` | State |
|---|---|---|
| true | any | suspended |
| false | true | paused |
| false | false | active |

Suspension takes precedence because a voluntary resume would not make a suspended monitor run.

`ListMonitorsRequest` parses each query key on its own. An invalid value (an unknown enum, a non-string, an over-long search or a non-positive page) is dropped and reported in `rejectedFilters`, and the page renders an error alert with the remaining filters. The request does not fail validation, because a failed GET validation redirects to the previous URL; for a directly opened list that would be the same invalid URL and would loop. This is a deliberate deviation from the plan's "standard validation error", recorded in TASKS.

Results are ordered by name and then ID, 20 per page (`MonitorListing::PER_PAGE`). Rows with equal names therefore never swap between pages. A page beyond the last one returns an empty page with a link back to the first. The pagination component lists every page number, which is bounded by the largest plan quota (200 monitors, 10 pages).

## Forms and secrets

`MonitorForm` is shared by the create and edit pages:
- a type selector shows only the selected type's section (HTTP, which keyword reuses, TLS, DNS or heartbeat);
- `buildPayload()` sends only the common fields and those of that type, because the server rejects irrelevant keys;
- numeric inputs stay strings so empty and partial values reach validation unchanged;
- an empty TLS warning and an empty DNS resolver are sent as null, and an empty body as null ("no body").

When editing, URL, header values and body are never prefilled. The form shows the stored redacted URL, the header names and the body size. Each secret has an explicit checkbox: "Replace URL", "Replace all headers" and "Replace or clear body". Unticked secrets are left out of the PATCH, and the server keeps the stored values it never sent. Changing the type requires the complete new configuration, as defined by the validator, so in that case the secret fields are always shown and sent. The same applies when creating.

Server errors appear inline (`aria-invalid`, `aria-describedby`, `role="alert"`) for known fields. Region, header and DNS-value errors appear beside their editors. Any other key, including the quota refusal on `monitor`, goes into a focusable alert at the top of the form. After a submission, focus moves to the first problem.

## Accessibility and states

- **Empty and error states.** The list distinguishes "no monitors yet" (with creation guidance) from "no matches" (with "Clear filters" and a link back to the first page). Rejected filters are announced in an alert.
- **Live regions and loading.** The result count is a polite live region, and the results region sets `aria-busy` while a filter navigation is loading.
- **Search form.** It uses `role="search"` with visible labels and an explicit submit button; there is no search-as-you-type.
- **Tables and status.** Tables have a caption and `scope` headers. Status and state badges are text, not color alone.
- **Pagination.** Inertia links inside `nav aria-label="Pagination"`, with `aria-current="page"`.
- **Detail page.** It uses a description list. Pause, resume and delete share one form, so a pending action disables the others, and deletion asks for confirmation. Viewers see no management controls. A plan-suspended monitor explains why it does not run and offers no pause or resume. A monitor outside the quota explains why resuming would be refused.
- **Creation control.** Viewers read "read-only access", and a full quota shows the plan message instead of the "New monitor" link. The create page warns in advance, but the server still decides.

## Tests

| Suite | Coverage |
|---|---|
| tests/Integration/MonitorPagesTest.php (PostgreSQL) | Case-insensitive search on the three columns and none on the encrypted URL; literal `%`, `_` and `\`; same-name foreign monitors never listed; explicit predicate beyond TeamScope; every filter and their combinations, with suspension precedence; deterministic pagination with ties and a page beyond the last; invalid filters reported without redirect; empty team versus empty result; no secret, header value, body or ciphertext in index/show/edit props, with no-store headers; usage from the effective plan and trial; quota position; `can.*` for four roles; create redirect with its readable flash |
| tests/Feature/MonitorListingTest.php | State precedence, filter invariants, LIKE escaping and the full-quota boundary, without a database |
| tests/Integration/TeamAuthorizationMatrixTest.php | The four GET routes in the role × membership-state matrix, guest/unverified gates, Inertia projections and foreign monitor IDs under an accessible team |
| tests/frontend/Monitors.test.tsx | Input kept after a quota refusal with retry, empty and no-match states, rejected filters, filter submission with a loading state, keyboard pagination preserving filters, creation unavailable to viewers or at full quota, a DNS creation sending only relevant fields, edits without resent secrets, secrets required on type change, inline errors with focus, safe detail with confirmed deletion, plan suspension |

## Integrated coverage (M2.06)

| Criterion | Evidence |
|---|---|
| All types | `MonitorLifecycleTest` runs each type through create, show, edit page, search with type filter, PATCH, pause, resume, delete and 404, on production routes only. `MonitorCrudTest` creates each type for each managing role. |
| Form ↔ server contract | `tests/Fixtures/monitor-form-payloads.json` is pinned to `buildPayload()` by `tests/frontend/MonitorPayloadContract.test.ts`. The same payloads are accepted by the production routes in `MonitorLifecycleTest`, and the contract `defaults` must equal `MonitorPresenter::defaults()` and the create page props. |
| Wrong input | `MonitorLifecycleTest` covers 23 creation cases across all types and common fields (missing or unknown values, bounds, other types' fields, loopback/private destinations and resolvers, oversized body) and 5 edit cases (type change without the new configuration, private URL, another type's field, bounds, empty patch). Each expects the field error, a redirect back, no persistence or unchanged row, and no echoed input or secret. Validator boundaries remain in `MonitorInputValidationTest`/`MonitorInputSyntaxTest`. |
| Permissions | `TeamAuthorizationMatrixTest` crosses every monitor route with role × membership state. `MonitorLifecycleTest` adds viewer denial on create/edit pages and every mutation for each type, with the stored rows unchanged. |
| Isolation | `MonitorLifecycleTest` keeps another team's monitors out of results even when they share the searchable TLS host or DNS name, and makes them 404 under the actor's team. Foreign IDs on every route are covered by the matrix, and same-name results by `MonitorPagesTest`. |
| Sixth Free monitor via UI, concurrent | `MonitorQuotaConcurrencyTest` uses the real HTTP server with Inertia submissions. A sixth creation is observed waiting on the Team lock held by an uncommitted fifth creation, and is then refused with the quota message shown as `props.errors.monitor` on the next page. In a burst of four parallel submissions for the last slot, exactly one succeeds. The form keeps its input after the refusal (DOM). |

The JSON fixture has a `defaults` object, which must equal `MonitorPresenter::defaults(300)`, and a `cases` object keyed by monitor type. Each case holds:
- `form`: the fields a user changes from the blank creation state;
- `create`: the exact creation payload;
- for `http` and `keyword` only, `edit`: the same-type patch with no secret replaced.

Form numbers stay strings, as the browser sends them. Destinations are reserved `.invalid` names, and the token values are synthetic.

## Limits

- No real browser (Playwright or similar) drives the pages. By user choice, UI coverage combines DOM tests of the React components, the shared payload contract and Inertia-protocol requests to the real server.
- Monitors have no target column in the list, because an HTTP target would require decryption.
- Dates are formatted in the viewer's browser locale. Team-timezone display arrives with dashboards (M6).
- Real-time status updates are M6, and scheduling is M3.
