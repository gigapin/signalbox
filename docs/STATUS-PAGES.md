# Status pages

A status page is a public page of a team: address, title, description, appearance, visibility, optional password and ordered components. Visitors read the current state, 90 days of history and explicitly published incident entries, through a short application cache.

## Data

| Table | Content |
|---|---|
| `status_pages` | `slug` unique across all teams, `title`, `description`, `theme_json`, `is_public`, `password_hash`, `created_by`. |
| `status_page_components` | `name`, `position`, unique per page. Replaced entirely on each save, so identities are not stable. |
| `status_page_component_monitors` | Which monitors a component groups. |

**Deviation from the specification:** the join table replaces `monitor_ids_json`. Composite keys make a monitor of another team impossible at the database level, a deleted monitor leaves every page on its own, and a monitor cannot appear twice on a page. `custom_domain` is not created yet: it arrives with M8.05.

## Rules

`ManageStatusPages` applies them under the Team lock, after a fresh policy check.

| Rule | Value |
|---|---|
| Address | 3 to 63 lowercase letters, digits and inner hyphens; free across all teams. A race between teams is settled by the unique index and reported as a normal refusal. |
| Title, description | Up to 120 and 500 characters; a blank description is stored as null. |
| Components | Up to 50 per page, with distinct names ignoring case, each grouping 1 to 100 monitors. A page may have no components. |
| Monitors | Of the same team only, once per page. A foreign monitor gets the same refusal as one that does not exist. |
| Theme | `mode` is `system`, `light` or `dark`. `accent` is `emerald`, `blue`, `violet`, `amber` or `slate`. Presets instead of free colors: no submitted value reaches the styles, and the accent only colors a decorative bar, so text contrast does not depend on it. |
| Password | At least 8 characters and at most 72 bytes, the part bcrypt reads. Only the hash is stored, and it is never sent to the browser. |
| Plan limit | `status_pages` in `config/plans.php`: 1, 3, 10, 100 for Free, Pro, Business, Internal. Drafts count. |

A component whose monitors were all deleted stays on the page, without monitors. The settings page says so, and the next save requires choosing at least one or removing the component.

After a downgrade, pages above the limit keep working and can be edited; only new ones are refused. What happens to them belongs to M9.04–M9.05.

## Permissions

Owners and admins manage pages; every member reads the settings page. The page publishes information outside the team, so it follows the rule of notification channels rather than the one of monitors. Routes and results are in [AUTHORIZATION-MATRIX.md](AUTHORIZATION-MATRIX.md).

## Public surface

| Route | Behavior |
|---|---|
| `GET /status/{slug}` | A draft, an unknown address and an invalid one all answer 404, for members of the team too: there is no preview yet. A protected page answers with the password form, whose only data is the address. Otherwise it answers with the page. |
| `POST /status/{slug}/unlock` | 5 attempts a minute per address and visitor. A draft answers 404 even with the right password. |

`PublicStatusPages` is the only code that reads a page without a tenant, apart from the boolean address check in the action. Its projection lists the fields. Monitor names, identities, the team and the maintenance reason stay out, because they can be internal.

Unlocking is per session and per page. The session holds a keyed fingerprint of the current hash, so a new password locks out whoever entered the old one, while removing the password opens the page for everyone. The session identity rotates on unlock.

Responses use `Cache-Control: no-store, private`, also for pages without a password. Inertia adds shared props to every response, including `auth.user` for a logged-in visitor, and each response sets a session cookie, so none of them can go into a shared HTTP cache.

## What visitors read

### Current state

`PublicHealthRules` decides it, for each monitor and then for each group.

| Monitor | State |
|---|---|
| Paused or suspended by the plan | Not counted: it is not being watched. |
| Unresolved incident | `outage`, also during maintenance. |
| Maintenance window running, end excluded | `maintenance`. The reason stays private. |
| Never observed, or last check older than 2 intervals plus the heartbeat grace | `unknown`. An observation does not hold forever (ADR-009). |
| Otherwise | `operational`, also after a first failed check: only a confirmed incident is an outage, because the threshold exists to avoid false alarms. |

A component or a page takes `outage` when all its parts are in outage, then `partial_outage` when any is, then `maintenance`, `unknown` and `operational`. With nothing being watched the answer is `unknown`, so "All systems operational" appears only when it is true.

The state follows monitoring even when nobody has published anything: a visitor can see an outage before any text about it.

### History

`PublicStatusHistory` reads the daily rollups: the 90 whole UTC days before today, on every plan (ADR-008). Today is left out because its rollup does not exist until the day closes, and the current state covers it.

The durations of the monitors of a component are added together, so percentages are time-weighted, following ADR-009: uptime is `U / (U + D)` and coverage is `(U + D) / (U + D + X)`. A day without observed time has null uptime and is shown as no data, never as available. A monitor joining a component brings its past days with it.

Days are UTC for every visitor and team, as the rollups are, and the page says so. The period uptime excludes time without data, and the page says that too.

### Incidents

`PublicIncidentFeed` lists the entries published in the last 90 days, for the monitors on the page, at most 25 incidents, newest publication first. An incident appears only once an entry is published. Its status is the one of its latest published entry, so a private change reveals nothing. The author, declared cause, error class and monitor name stay out; the affected component is named instead.

## Cache

`StatusPageCache` stores only the visitor projection, in the application cache, under `status-pages:{team}:{page}:content:{generation}`. HTTP responses are never stored by intermediaries.

Every request still reads the page row. Draft state, address and password are always live, so a page closes at once and a locked visitor never reaches the stored content. The content does not depend on the visitor.

| Change | Effect |
|---|---|
| Page edit or deletion | Immediate, for that page only |
| Entry published, incident opened, reopened or resolved | Immediate, for the pages showing the monitor |
| Maintenance window scheduled, changed or cancelled | Immediate |
| Monitor edited, paused, resumed or deleted | Immediate |
| Check results, a window starting or ending by itself, expiry of an observation, a new daily rollup | Within 60 seconds |
| Plan suspension of monitors | Within 60 seconds, as the action does not exist yet (M9.05) |

Check results do not invalidate: a monitor checked every 30 seconds would empty the cache all the time, and a real outage appears through the incident event anyway.

Invalidating raises a generation that is part of the key, after the transaction commits, so a calculation running during the change cannot store old content where it would be read. A rollback changes nothing. If the cache fails, pages are calculated on each visit and the failure is logged by class only; a missed invalidation lasts at most 60 seconds.

Several visitors can calculate the same page at once when an entry expires; there is no lock to prevent it. No load measurement has been made.

## Verification

```sh
docker compose run --rm php-test vendor/bin/pest tests/Feature/PublicHealthRulesTest.php tests/Integration/StatusPageManagementTest.php tests/Integration/PublicStatusPageTest.php tests/Integration/PublicStatusContentTest.php tests/Integration/StatusPageCacheTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/StatusPages.test.tsx
```

The acceptance gate remains `./scripts/verify.sh`. It builds the assets, while CI runs the backend without them, so a test rendering HTML must call `withoutVite()`. To reproduce CI's condition:

```sh
docker compose run --rm -v /var/www/html/public/build php-test vendor/bin/pest --fail-on-skipped
```
