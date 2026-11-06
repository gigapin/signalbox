# Daily notification quiet hours (M5.05)

A notification channel may postpone incident deliveries every day in an explicit IANA timezone. Owners/admins configure the interval from **Notifications → channel → Configure quiet hours**. All team members see the saved interval; only managers see its editor. Existing channels start without quiet hours. Following the browser-test decision of November 5, 2026, quiet hours defer alerts instead of permanently suppressing them: a still-open incident must become known even when this is the recipient's only channel. Scope and measured verification results are recorded in [TASKS.md](TASKS.md); delivery guarantees remain in [NOTIFICATION-DELIVERY.md](NOTIFICATION-DELIVERY.md).

## Configuration and persistence

Migration `2026_10_30_000020_add_channel_quiet_hours.php` adds three nullable columns to `notification_channels`:

| Column | Meaning |
|---|---|
| `quiet_hours_start` | Inclusive daily local time, exactly `HH:mm` |
| `quiet_hours_end` | Exclusive daily local time, exactly `HH:mm`, distinct from the start |
| `quiet_hours_timezone` | Explicit IANA timezone supported by the PHP runtime's named timezone list, including `UTC` |

All three must be null (disabled) or present together. PostgreSQL checks completeness, time syntax, distinct boundaries and a nonblank timezone; the domain and Form Request additionally check the timezone against `DateTimeZone::ALL`. Numeric offsets and unknown names are refused. A zero-length interval does not represent an entire day: disable quiet hours explicitly or use the existing channel pause control for continuous silencing.

`App\Data\Notifications\QuietHours` is the immutable pure rule shared by validation and admission. `NotificationChannel::quietHours()` hydrates it. Credentials remain in the separate encrypted provider configuration: changing quiet hours preserves activation, verification, pending codes and destination fingerprint. It also preserves every prepared message UUID/body. Changes make pending deferred rows due for reevaluation, so shortening or disabling the interval releases eligible alerts without waiting for the former boundary; extending it causes the next admission to defer again. Active leases are left alone. Terminal outcomes remain terminal except for the narrowly defined legacy migration below.

The dedicated endpoint is:

```text
PATCH /teams/{team}/notification-channels/{channel}/quiet-hours
```

It accepts `quiet_hours: {start, end, timezone}` with no extra keys, or an explicit `quiet_hours: null` to disable it. Missing, empty and partial intervals are rejected. `UpdateChannelQuietHoursRequest` authorizes the scoped channel; `ManageNotificationChannels::setQuietHours()` repeats the policy under Team → channel locks. The existing destination-edit endpoint leaves quiet hours unchanged. Guests/unverified users follow the existing authentication gates, member/viewer changes return 403, foreign/revoked teams and foreign channel IDs return 404. No caller-supplied team or actor replaces the authenticated route identities.

## Admission, deferral and grouping

`NotificationDeliveryLedger::acquire()` reloads the channel inside its explicit tenant after acquiring the existing Team → Monitor → Incident → delivery locks. Before any provider attempt or expired-lease recovery, it checks the current quiet-hours rule. Channel changes also take the Team lock, so a newly committed rule is observed by a worker waiting for admission.

Inside the interval, the due row remains `pending`. Its `available_at` and hidden `quiet_hours_deferred_until` become the first permitted UTC instant; its lease is cleared to fence late workers. No additional provider attempt is consumed and no provider is called. The existing `notifications:dispatch` scan republishes it when due, without requiring another incident event. The wait marker is cleared when a provider attempt starts, so later quiet-hours edits cannot shorten provider retry backoff. The rule applies to openings, escalations, resolution summaries and retries, regardless of severity. The timestamp is earliest eligibility: the ten-second scan, queue load and provider latency can delay delivery.

At release, lifecycle, cycle, maintenance, channel verification/activation and destination are checked again. A resolved incident cancels deferred openings/escalations; an incident resolved before any accepted alert has no late opening or unnecessary recovery message. An owed recovery summary also waits until quiet hours end. Reopening cancels obsolete-cycle waits as before.

If any due opening/escalation in a pending group has been deferred, admission retains the earliest prepared row and suppresses its due siblings for the same tenant, incident, cycle, channel and destination. Workers may arrive in any order: grouping occurs under the shared Team/incident locks before provider admission. One stable UUID/body is retained across retries; other incidents and recipients never merge. Future steps remain scheduled and may still escalate later. This avoids a burst of accumulated steps when quiet hours end.

Admission uses the actual attempt time: a due envelope first processed after quiet hours may send. Preparation preserves future steps. Verification-code delivery is independent and remains available so managers can verify channels at any hour. Maintenance suppression retains its existing priority and terminal semantics.

An admitted request can finish after quiet hours begin and record its real outcome while its lease is live. A retry subsequently admitted inside the interval is deferred. An expired worker is fenced when its row is deferred; its late acknowledgement cannot overwrite the outcome. Waiting does not reset consumed attempts or the retry budget. Invalid stored times/timezones fail closed with a safe terminal failure, without leaking the malformed value or retrying indefinitely.

## Midnight and DST semantics

Intervals use the **local wall clock**, not a fixed UTC offset or a fixed elapsed duration. Convert each real instant into the saved timezone and compare `HH:mm`:

- Start before end: `start <= local time < end` on the same local day.
- Start after end: `local time >= start OR local time < end`, wrapping through midnight.
- Seconds belong to their minute: `06:59:59` is inside a `22:00–07:00` interval; `07:00:00` is outside.

No local start/end datetime is constructed, so there is no ambiguous/nonexistent-time normalization to guess. During the autumn rollback, **both** occurrences of a repeated local minute follow the rule; the real UTC interval can have a gap if the wall clock leaves and re-enters a narrow range. In spring, nonexistent local minutes never occur. A range wholly inside the missing hour silences nothing that day; a range spanning the jump covers whichever real instants have matching local times.

`QuietHours::nextAllowedAt()` examines real timezone transitions and local-end candidates under every applicable offset over a bounded three-day horizon. A repeated end chooses the first real exit; a skipped end releases at the transition if its resulting wall clock is outside the interval. It never relies on PHP choosing an ambiguous local datetime. Admission rechecks the interval if delayed publication reaches a second quiet occurrence.

Examples in Europe/Rome for 2026: `02:15–02:45` on October 25 silences both 00:15–00:45 UTC and 01:15–01:45 UTC. The same local range silences no real instants on March 29, when the clock jumps from 01:59:59 to 03:00:00. Overnight ranges automatically follow each day's actual offset; they are not assumed to last 24 hours or a constant number of hours. The browser's timezone does not reinterpret the saved local times, and changing the team's calendar timezone does not change a channel's explicit timezone.

## Operations and verification

Migration `2026_11_05_000025_defer_quiet_hours_notifications.php` adds the nullable deferred boundary. It also restores legacy quiet-only suppressed openings/escalations to pending **only** for still-open, current-cycle incidents with active verified channels and prepared messages. Other suppression reasons, resolved/stale cycles and inactive recipients remain untouched. The normal ledger rechecks destination fingerprints, maintenance and quiet hours before restored rows can send; due siblings are grouped. UUIDs, encrypted payloads, dedupe keys and attempt counts are preserved. Applying the migration can therefore resume notifications about ongoing incidents.

No dependency, lockfile, environment variable or application key changes. Apply the migrations deliberately to development storage; verification migrates only guarded test PostgreSQL. Rebuild the editor and reload long-lived application workers after adopting this source version:

```sh
docker compose run --rm node npm run build
docker compose stop queue scheduler
docker compose run --rm web php artisan migrate
docker compose up -d --no-deps --force-recreate web queue scheduler
```

Stop the old workers before migration recovery makes legacy alerts pending, then start the revised ledger. The application database and processes are not changed by automated verification.

`QuietHoursTest` covers validation, boundaries, midnight, input-offset independence, fractional offsets and DST in Rome/New York. `NotificationQuietHoursTest` covers the route/action, projections, code availability, idempotence, unchanged recipient state and PostgreSQL constraints. `IncidentNotificationDeliveryTest` covers all kinds, current-rule changes, retry/expired leases, late receipts, future steps, tenant/recipient isolation, DST admission and invalid stored zones. `NotificationConcurrencyTest` uses independent sessions to commit a quiet-hours change while a worker waits on the Team lock. `TeamAuthorizationMatrixTest` inventories the new route across all roles, authentication states and foreign bindings. `Notifications.test.tsx` covers display, enable/edit/disable submissions and announced field errors.

The acceptance gate remains `./scripts/verify.sh`. Actual results belong in TASKS; no remote CI, PHP 8.5 integration, real browser or live-provider run is implied here. The complete notification acceptance matrix is documented in [NOTIFICATION-ACCEPTANCE.md](NOTIFICATION-ACCEPTANCE.md) (M5.06).
