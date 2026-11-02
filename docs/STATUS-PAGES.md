# Status pages

A status page is a public page of a team: address, title, description, appearance, visibility, optional password and ordered components. Visitors read the current state, 90 days of history and explicitly published incident entries, through a short application cache.

## Data

| Table | Content |
|---|---|
| `status_pages` | `slug` unique across all teams, `title`, `description`, `theme_json`, `is_public`, `password_hash`, `created_by`, pending custom hostname/proof and last verification attempt. |
| `status_page_components` | `name`, `position`, unique per page. Replaced entirely on each save, so identities are not stable. |
| `status_page_component_monitors` | Which monitors a component groups. |
| `status_page_subscribers` | Encrypted mailbox, keyed uniqueness digest, consent generation, password fingerprint, verification deadline and opt-in/revocation times. |
| `status_page_deliveries` | Encrypted verification/publication content, frozen recipient generation, stable message UUID, deduplication key, attempt budget and fenced lease. |
| `status_domain_bindings` | Globally exclusive hostname association with the exact tenant/page/proof generation and a 24-hour lease. |

**Deviation from the specification:** the join table replaces `monitor_ids_json`. Composite keys make a monitor of another team impossible at the database level, a deleted monitor leaves every page on its own, and a monitor cannot appear twice on a page. Custom domains use pending page claims plus a separate exclusive registry, so an unverified claim cannot reserve another owner's name.

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
| `POST /status/{slug}/subscribe` | Live public page and session unlock required. Bounded RFC email, no DNS call. Five requests per minute per visitor IP across all pages. Generic acknowledgement for new, pending and active addresses. |
| `GET/POST /status/subscriptions/{teamId}/{pageId}/{subscriberId}/{generation}/{action}` | Relative signed capability for `verify` or `unsubscribe`, with CSRF on POST. GET shows a generic confirmation form; only POST changes consent. No mailbox, title or page content is projected. |

`PublicStatusPages` resolves public slugs; `StatusDomainRegistry` separately resolves live verified custom hosts. Apart from these public discovery boundaries and the boolean address check in the action, page reads require an explicit tenant. The visitor projection lists fields. Monitor names, identities, the team and the maintenance reason stay out, because they can be internal.

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

## Email subscriptions

`StatusSubscriptions` serializes requests under the Team lock used by page edits and incident publication. A lowercase, trimmed RFC mailbox is encrypted at rest; an HMAC under the application key supplies the per-page uniqueness key. The same address can subscribe independently to several pages, including pages of different teams. Subscriber and delivery models require `TenantContext`; composite PostgreSQL keys additionally bind deliveries to their page, recipient and published entry.

Consent, publication snapshots and attempt admission are separate services. A separate transactional outbox keeps anonymous mailbox consent out of team notification channels and escalation rules, while reusing their queue, bounded SMTP configuration and delivery policy. Laravel's mailer supplies the provider boundary; there is no additional transport abstraction for this single email path.

### Consent and capabilities

- New recipients receive a generic confirmation email through the durable outbox. It reveals no protected page title or incident content. Its verification capability lasts one hour, with the exact deadline excluded. A pending address can request another email after ten minutes; rotating the generation revokes previous verification and unsubscribe capabilities. Already verified addresses produce the same acknowledgement and no extra mail.
- A visitor must enter the current password before subscribing to a protected page. Consent is bound to a keyed fingerprint of that password, including the absence of one. Adding, replacing or removing the password stops old consent from confirming or receiving further emails; subscribing again after unlocking requires new mailbox confirmation. A draft does not accept requests, confirmations or delivery attempts. Returning to public with the same password permits existing consent again.
- Signed links contain identities and a random generation, never the address. Outgoing links use `APP_URL`, independently of the request Host. The signature covers the path and query; tampering with any coordinate fails before lookup. Lookup then establishes the signed tenant and checks page, recipient and current generation together. `APP_URL` must be the externally reachable origin; changing the application signing key invalidates capabilities and consent fingerprints.
- GET is inert, so an email security scanner cannot confirm or cancel consent. The user presses a button to send a CSRF-protected POST. Responses are private/no-store with `Referrer-Policy: no-referrer`. Verification and unsubscribe POSTs are idempotent within the current generation. The unsubscribe link has no time expiry and still works after a page becomes a draft or changes password. Resubscribing rotates it and requires fresh opt-in.

### Publication and recipients

`ManageIncidents::publish` calls `PreparePublicUpdateEmails` only when an entry first becomes public, inside its existing transaction. It snapshots verified, non-revoked subscribers whose consent matches the password of a currently public page selecting the incident's monitor. Pages sharing a monitor send independently. Private notes, automatic incident transitions and publication replays send nothing. New subscribers get future publications only; there is no backfill.

The encrypted email snapshot contains the public page title, public component label, published entry status/time/text, page URL and a revocable unsubscribe link. It excludes monitor names, team information, author identities, private cause notes and private timeline entries. Text is rendered literally in a plain-text email. Publication and every recipient intent commit or roll back together. Preparation does no network I/O and iterates recipients in batches of 100.

Public subscriber emails follow explicit publication, independently of the internal escalation channels, maintenance silencing and quiet hours. A manager can publish a maintenance message intentionally; it is delivered to consenting subscribers.

### Delivery and recovery

`status-pages:dispatch-emails` runs every ten seconds with existing Redis scheduler coordination. Its global scan reads only due identities, then publishes scalar jobs to Redis's `notifications` queue. There is no immediate HTTP/after-commit broker submission: the periodic scan is the publication path, so a crash after the database commit cannot lose an email intent. One broker failure does not prevent the rest of the pass and produces a nonzero command result. Duplicate broker envelopes are harmless.

`StatusEmailLedger` admits one attempt under Team → Page → Subscriber → Delivery locks, rechecking the current generation, consent, password, public visibility and monitor selection on every attempt. Old verification emails are suppressed after confirmation, generation rotation or expiry. Unsubscribe suppresses unclaimed waiting rows immediately. A changed page or revoked consent suppresses an update at its next admission. A corrupt encrypted payload becomes terminal `failed` without logging its contents.

The worker calls the explicitly configured `smtp` mailer after committing its lease, so the default `log` mailer cannot record capability links. It shares the existing notification policy: three acquired attempts, 30/120-second backoff, 60-second worker timeout, 75-second lease and Redis's 90-second reservation. `tries=1` prevents hidden broker retries. A timeout's `failed()` hook or later expired-lease scan accounts for failure; recovery starts backoff when it observes expiry. Fenced completion rejects stale workers. No provider exception text is persisted or logged.

`sent` means SMTP acceptance, not inbox delivery. The UUID, Message-ID and body stay stable across retries, but SMTP cannot commit atomically with PostgreSQL: acceptance followed by worker failure can produce a repeated email. Already admitted sends can complete after unsubscribe or a visibility/password change; future admission is stopped. Email ordering across separate published entries is not guaranteed, and no throughput benchmark or real SMTP delivery is claimed.

### Adoption

Apply `2026_11_02_000023_create_status_page_subscriptions.php` to the intended development database before loading this code in workers, rebuild assets, and reload the queue/scheduler code:

```sh
docker compose exec web php artisan migrate
docker compose run --rm node npm run build
docker compose up -d --no-deps --force-recreate queue scheduler
```

Configure the existing SMTP settings and `APP_URL`; no new dependency, environment variable or supervisor is required. Verification migrates only disposable test storage and does not perform these development operations. Recipient rows remain until their page or team is deleted; delivery history also cascades with its referenced incident entry. No independent email retention cleanup is introduced here.

## Custom domains

[STATUS-DOMAINS.md](STATUS-DOMAINS.md) documents DNS TXT proof, expiring exclusive associations, restricted host routing, separate public sessions and TLS admission. Owners/admins configure and verify a hostname independently of page contents. Custom origins expose only their associated page and its password/subscription forms; the primary page URL remains available. Certificates are handled by the deployment proxy, not issued by application requests.

## Verification

[STATUS-PAGE-SEO.md](STATUS-PAGE-SEO.md) describes initial HTML metadata, the public summary without JavaScript, canonical aliases, restricted noindex documents and the decision to keep optional React SSR disabled.

```sh
docker compose run --rm php-test vendor/bin/pest tests/Feature/PublicHealthRulesTest.php tests/Integration/StatusPageManagementTest.php tests/Integration/PublicStatusPageTest.php tests/Integration/PublicStatusContentTest.php tests/Integration/StatusPageCacheTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/StatusPages.test.tsx
docker compose run --rm php-test vendor/bin/pest tests/Integration/StatusSubscriptionsTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/StatusSubscriptions.test.tsx
docker compose run --rm php-test vendor/bin/pest tests/Integration/StatusDomainsTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/StatusDomains.test.tsx
docker compose run --rm -v /var/www/html/public/build php-test vendor/bin/pest tests/Feature/StatusPageDocumentsTest.php tests/Integration/StatusPageSeoTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/StatusPageMetadata.test.tsx
```

The acceptance gate remains `./scripts/verify.sh`. It builds the assets, while CI runs the backend without them, so a test rendering HTML must call `withoutVite()`. To reproduce CI's condition:

```sh
docker compose run --rm -v /var/www/html/public/build php-test vendor/bin/pest --fail-on-skipped
```
