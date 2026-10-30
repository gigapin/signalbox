# Daily notification quiet hours (M5.05)

A notification channel may silence incident deliveries every day in an explicit IANA timezone. Owners/admins configure the interval from **Notifications → channel → Configure quiet hours**. All team members see the saved interval; only managers see its editor. Existing channels start without quiet hours. Scope and measured verification results are recorded in [TASKS.md](TASKS.md); delivery guarantees remain in [NOTIFICATION-DELIVERY.md](NOTIFICATION-DELIVERY.md).

## Configuration and persistence

Migration `2026_10_30_000020_add_channel_quiet_hours.php` adds three nullable columns to `notification_channels`:

| Column | Meaning |
|---|---|
| `quiet_hours_start` | Inclusive daily local time, exactly `HH:mm` |
| `quiet_hours_end` | Exclusive daily local time, exactly `HH:mm`, distinct from the start |
| `quiet_hours_timezone` | Explicit IANA timezone supported by the PHP runtime's named timezone list, including `UTC` |

All three must be null (disabled) or present together. PostgreSQL checks completeness, time syntax, distinct boundaries and a nonblank timezone; the domain and Form Request additionally check the timezone against `DateTimeZone::ALL`. Numeric offsets and unknown names are refused. A zero-length interval does not represent an entire day: disable quiet hours explicitly or use the existing channel pause control for continuous silencing.

`App\Data\Notifications\QuietHours` is the immutable pure rule shared by validation and admission. `NotificationChannel::quietHours()` hydrates it. Credentials remain in the separate encrypted provider configuration: changing quiet hours preserves activation, verification, pending codes and destination fingerprint. It also preserves every prepared message UUID/body. Changes affect future admission immediately, including messages prepared before the change; terminally suppressed rows are never revived.

The dedicated endpoint is:

```text
PATCH /teams/{team}/notification-channels/{channel}/quiet-hours
```

It accepts `quiet_hours: {start, end, timezone}` with no extra keys, or an explicit `quiet_hours: null` to disable it. Missing, empty and partial intervals are rejected. `UpdateChannelQuietHoursRequest` authorizes the scoped channel; `ManageNotificationChannels::setQuietHours()` repeats the policy under Team → channel locks. The existing destination-edit endpoint leaves quiet hours unchanged. Guests/unverified users follow the existing authentication gates, member/viewer changes return 403, foreign/revoked teams and foreign channel IDs return 404. No caller-supplied team or actor replaces the authenticated route identities.

## Admission and suppression

`NotificationDeliveryLedger::acquire()` reloads the channel inside its explicit tenant after acquiring the existing Team → Monitor → Incident → delivery locks. Before any provider attempt or expired-lease recovery, it checks the current quiet-hours rule. Channel changes also take the Team lock, so a newly committed rule is observed by a worker waiting for admission.

Inside the interval, the due row becomes terminal `suppressed` with the fixed safe reason `The channel is within its configured quiet hours.` No additional attempt is consumed, no provider is called and no deferred notification is created. The rule applies to openings, escalations, resolution summaries and retries, regardless of severity. It does not change incident transitions, checks or absolute escalation delays. Future steps outside the interval can still send while an incident remains open. An incident with no accepted opening/escalation produces no recovery summary.

Admission uses the actual attempt time: a due envelope first processed after quiet hours may send. Preparation does not discard future steps merely because the channel is currently quiet. There is no catch-up for a row already suppressed. Verification-code delivery is independent and remains available so managers can verify channels at any hour. Maintenance suppression retains its existing priority and semantics.

An admitted request can finish after quiet hours begin and record its real outcome while its lease is live. A retry subsequently admitted inside the interval is suppressed. An expired worker is fenced when its row is suppressed; its late acknowledgement cannot overwrite the outcome. Invalid stored times/timezones fail closed with a safe terminal failure, without leaking the malformed value or retrying indefinitely.

## Midnight and DST semantics

Intervals use the **local wall clock**, not a fixed UTC offset or a fixed elapsed duration. Convert each real instant into the saved timezone and compare `HH:mm`:

- Start before end: `start <= local time < end` on the same local day.
- Start after end: `local time >= start OR local time < end`, wrapping through midnight.
- Seconds belong to their minute: `06:59:59` is inside a `22:00–07:00` interval; `07:00:00` is outside.

No local start/end datetime is constructed, so there is no ambiguous/nonexistent-time normalization to guess. During the autumn rollback, **both** occurrences of a repeated local minute follow the rule; the real UTC interval can have a gap if the wall clock leaves and re-enters a narrow range. In spring, nonexistent local minutes never occur. A range wholly inside the missing hour silences nothing that day; a range spanning the jump covers whichever real instants have matching local times.

Examples in Europe/Rome for 2026: `02:15–02:45` on October 25 silences both 00:15–00:45 UTC and 01:15–01:45 UTC. The same local range silences no real instants on March 29, when the clock jumps from 01:59:59 to 03:00:00. Overnight ranges automatically follow each day's actual offset; they are not assumed to last 24 hours or a constant number of hours. The browser's timezone does not reinterpret the saved local times, and changing the team's calendar timezone does not change a channel's explicit timezone.

## Operations and verification

No dependency, lockfile, environment variable or application key changes. Apply the migration deliberately to development storage before editing quiet hours; verification migrates only guarded test PostgreSQL. Reload long-lived application workers after adopting this source version:

```sh
docker compose run --rm web php artisan migrate
docker compose up -d --no-deps --force-recreate queue scheduler
```

`QuietHoursTest` covers validation, boundaries, midnight, input-offset independence, fractional offsets and DST in Rome/New York. `NotificationQuietHoursTest` covers the route/action, projections, code availability, idempotence, unchanged recipient state and PostgreSQL constraints. `IncidentNotificationDeliveryTest` covers all kinds, current-rule changes, retry/expired leases, late receipts, future steps, tenant/recipient isolation, DST admission and invalid stored zones. `NotificationConcurrencyTest` uses independent sessions to commit a quiet-hours change while a worker waits on the Team lock. `TeamAuthorizationMatrixTest` inventories the new route across all roles, authentication states and foreign bindings. `Notifications.test.tsx` covers display, enable/edit/disable submissions and announced field errors.

The acceptance gate remains `./scripts/verify.sh`. Actual results belong in TASKS; no remote CI, PHP 8.5 integration, real browser or live-provider run is implied here. The complete notification acceptance matrix remains M5.06.
