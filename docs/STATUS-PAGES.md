# Status pages

A status page is a public page of a team: address, title, description, appearance, visibility, optional password and ordered components. M8.01 delivers management and the public surface with component names only. Current status and history come with M8.02, public cache with M8.03.

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

`PublicStatusPages` is the only code that reads a page without a tenant, apart from the boolean address check in the action. Its projection lists the fields: title, description, theme and component names. Monitor names, identities and the team stay out, because a monitor name can be internal.

Unlocking is per session and per page. The session holds a keyed fingerprint of the current hash, so a new password locks out whoever entered the old one, while removing the password opens the page for everyone. The session identity rotates on unlock.

Responses use `Cache-Control: no-store, private`. M8.03 must change this for unprotected pages, and must keep in mind that Inertia adds shared props to every response, including `auth.user` for a logged-in visitor: those cannot end up in a shared cache.

## Verification

```sh
docker compose run --rm php-test vendor/bin/pest tests/Integration/StatusPageManagementTest.php tests/Integration/PublicStatusPageTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/StatusPages.test.tsx
```

The acceptance gate remains `./scripts/verify.sh`.
