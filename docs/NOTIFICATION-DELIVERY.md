# Durable incident notification delivery

M5.03 connects incident transitions to the provider adapters described in [NOTIFICATIONS.md](NOTIFICATIONS.md). M5.04 adds all escalation steps and maintenance silencing. Decision and tradeoffs: ADR-012 in [ARCHITECTURE.md](ARCHITECTURE.md). Work state and measured verification results: [TASKS.md](TASKS.md).

## Transaction and publication boundary

`IncidentLifecycle` emits `IncidentNotificationRequested` synchronously after recording each opening, reopening or resolution and **inside the existing incident transaction**. Its explicit listener, `PrepareIncidentNotifications::record()`, uses the same Team → Monitor → Incident lock order and does no network I/O. A preparation failure rolls back the incident, timeline and notification intent together.

Preparation records an `incident_notification_events` marker unique by team, incident, reopen cycle and kind (`opened` or `resolved`). This freezes the audience even when it is empty. An identical event replay cannot pick up a later policy edit, newly created channel or later activation. Reopening uses kind `opened` with a new cycle and event `incident.reopened`.

The existing `IncidentOpened`, `IncidentReopened` and `IncidentResolved` events remain after-commit events with identities only. Their explicit listener, `PublishNotificationDeliveries::publish()`, submits prepared rows through `NotificationWorkPublisher`; its Redis adapter never uses the synchronous default queue connection. Listener methods are named `record`/`publish`, not convention-discovered `handle`, so explicit registration does not create duplicate listeners.

The durable rows exist before these public events run. A process crash between commit and event dispatch, broker failure, missing broker acknowledgement or lost queue envelope leaves recoverable pending rows. `notifications:dispatch` rediscovers them every ten seconds with shared Redis scheduler coordination. It reads globally only owner/delivery identities and eligibility, then reloads each row inside its explicit tenant. Publication failures are isolated per delivery and produce a nonzero command result after the rest of the pass; diagnostics contain only team/delivery IDs. No provider request runs in the scheduler or publisher.

## Persistence

Apply `2026_10_29_000019_add_notification_delivery_outbox.php` after the two channel migrations. It creates the tenant-owned event marker and extends `notification_logs`:

| Field | Meaning |
|---|---|
| `cycle` | Incident reopen counter at preparation |
| `message_uuid` | Stable recipient-facing UUID, unique per team and unchanged by retries |
| `message_payload` | Application-encrypted, minimal `ChannelMessage` envelope; hidden from serialization |
| `destination_hash` | HMAC under the application key of the canonical family/configuration, excluding the webhook signing key; hidden |
| `available_at` | Earliest UTC acquisition time, including coalescing, initial policy delay or retry backoff |
| `claim_token` | Unique scalar queue-envelope ownership fence, retained after completion to reject stale hooks |
| `claim_until` | UTC expiry of the active attempt; null outside an acquired attempt |

The existing team/dedupe-key uniqueness enforces one row per incident/cycle/kind/channel, additionally keyed by zero-based step index for escalations. Opening/resolution keys keep their original format; resolutions have no step index. Runnable rows require a cycle, encrypted payload, destination hash and due time. Active leases require pending status and an ownership token. A partial index serves pending due rows. Legacy history remains readable; rows without a prepared UUID are never runnable. Model serialization and page presenters expose none of these internal fields. No delivery destination or credential is copied into the message payload.

The marker table has composite tenant/incident foreign keys, positive-or-zero cycles and a fixed kind list. It is deliberately internal: its query-builder reads/writes always include the explicit team identity. Team/incident deletion cascades both marker and deliveries; channel deletion preserves logs with a null recipient reference.

## Audience and coalescing

- An opening/reopening snapshots **every step** of the monitor override or team-default policy into delivery rows in the same transaction. No policy means an empty, frozen audience. Later policy edits/deletion, monitor reassignment or newly activated channels cannot change that cycle's prepared audience or delays.
- Each step's delay is absolute from confirmation, or the last reopening instant. The attempt is due at the later of that time and preparation time plus `notifications.delivery.coalesce_seconds` (default 5 s). The first step uses `opened`; all later steps use `escalated` with their zero-based `step_index`. The same channel may receive one message at each step; duplicate channel IDs within a step collapse. A failed earlier step does not postpone or prevent later steps; concurrent provider attempts for the same incident/channel remain serialized.
- Inactive/unverified selected channels get a suppressed log, rather than a later retroactive send. Duplicate channel IDs collapse to one delivery.
- Resolution suppresses unclaimed/expired pending openings **and all later escalations**, including their retries. If the entire episode recovered before an opening/escalation was accepted or in flight, no provider message is sent.
- A resolution summary targets each distinct channel with an accepted opening/escalation not covered by an already accepted summary, plus recipients whose attempt is still in flight. The selected destination fingerprint comes from that original delivery, independent of subsequent policy edits. A paused, deleted, unverified or changed destination is suppressed at execution.
- In-flight openings are allowed to record their real acceptance. A summary waits while another delivery for the same incident/channel owns a live lease. If no opening/escalation was accepted, it is suppressed.
- Reopening suppresses older waiting resolutions/openings. A final summary can cover an earlier unsummarized opening even when an intervening reopened opening coalesced away; an already summarized episode does not produce a redundant recovery message. An already-running provider request cannot be recalled.

Grouping is within the same incident and recipient. Unrelated monitors or tenants are never combined into one message. This stage does not introduce a cross-monitor digest or change the incident anti-flapping rules.

## Maintenance silencing (M5.04)

The ledger calls the existing tenant-scoped `MaintenanceSchedule::activeAt()` before admitting **every** opening, escalation or summary attempt, including retries and expired-worker recovery. Windows use UTC `[starts_at, ends_at)`: the start silences, the end allows delivery. Maintenance changes share Team → Monitor locks with admission, so a committed change is observed by the next admission.

A due delivery acquired during maintenance becomes terminal `suppressed` with a fixed safe reason and no additional provider attempt. It is not deferred or revived at the end of maintenance. Future steps retain their own schedule and may send once the window ends if the incident remains open. Preparation itself preserves future intents; it does not suppress a later step merely because maintenance is active when the incident transitions. Only the actual admission time decides silencing; an overdue envelope first processed after maintenance ends may still send. These rules avoid a deferred burst of deliveries that were already silenced without dropping future escalation steps.

An already admitted provider request cannot be recalled; its real acknowledgement can still be recorded during maintenance or after resolution, subject to the existing live lease/fence. Summaries still target only actually notified recipients and are themselves silenced during maintenance. Verification-code messages are separate from incident deliveries and remain available during maintenance. Quiet hours/timezone/DST behavior remains M5.05.

## Acquisition, retry and recovery

`DeliverIncidentNotification` contains only team ID, log ID, ownership UUID and scalar worker settings. `UseTenantContext` restores the preceding worker context after success or failure. Its `handle()` delegates to `DeliverNotification`; no serialized model is rehydrated before the tenant boundary.

`NotificationDeliveryLedger::acquire()` locks Team → Monitor → Incident → delivery, rechecks pending/due state, current cycle and open/resolved compatibility, maintenance, active/verified channel and destination fingerprint, and ensures there is no live attempt for the same incident/recipient. It increments `attempts` and commits a 75-second lease before returning a transient configuration/message DTO. The provider call occurs **outside that transaction**. A corrupt stored message/configuration becomes a terminal safe failure, preventing a poison row from retrying indefinitely.

The webhook signing key is excluded from destination fingerprinting because rotation does not change the verified destination; the next attempt uses the current key while keeping the same UUID/body. Editing the actual URL, address, phone, bot token or chat changes the fingerprint, even if the destination is subsequently reverified. Such pending messages are suppressed instead of being sent to a new recipient.

Default policy:

| Setting | Value |
|---|---|
| Maximum acquired attempts | 3 |
| Retry delay after failures 1 and 2 | 30 s, 120 s |
| Job/worker timeout | 60 s |
| Persistent lease | 75 s |
| Redis `retry_after` | 90 s |
| Compose Horizon shutdown grace | 80 s |
| Recovery scan | Every 10 s |

Timeout < lease < Redis reservation lifetime. Each broker job has `tries = 1` and `failOnTimeout = true`; these are **not check retries**. Notification retries are explicit persisted delivery attempts, bounded by `notifications.delivery.max_attempts` and `backoff_seconds`. Broker duplicates, admission deferrals and suppressed rows do not consume provider attempts. Delay means earliest eligibility, not guaranteed wall-clock delivery: queue/recovery load may add latency.

Expected `ChannelDeliveryFailed` outcomes are redacted with current configuration/message values and bounded to 1000 characters before persistence. Unexpected provider errors produce a fixed internal-error reason and a clean exception without the private original chain. `failed()` uses the immutable envelope token, because Laravel restores a fresh job instance for that hook. Completion/failure requires the matching live token; stale/expired workers cannot overwrite newer attempts or terminal outcomes. If no failed hook runs, the first recovery acquisition of an expired lease records that failed attempt and starts its backoff; the next due pass may acquire a fresh attempt. Crashes count toward the same budget.

`sent` means provider acceptance and sets `sent_at`; it cannot prove inbox/device delivery. SMS returns `suppressed`, never `sent`, and records only UUID/event/family metadata. HTTP adapters repeat DNS authorization/pinning on each attempt; no provider response body is retained. No client or broker retry can hide a failed monitoring observation.

## Recipient payload and idempotency limits

Incident messages use the existing signed envelope and carry `incident_id`, `monitor_id`, `cycle`, `severity`, `cause`, `started_at`, `confirmed_at`, nullable `resolved_at`, nullable `duration_seconds` and `reopened_count`. Text includes the monitor label, incident identity, state, severity and cause classification. Resolution adds duration and reopen count. Monitor URLs, headers, bodies, declared cause notes and private/public timeline messages are omitted. Duration follows the incident's existing wall-clock semantics, including short recovered gaps after reopening; it is not the SLA calculation.

Escalation envelopes use event `incident.escalated`, add zero-based `data.step_index`, and set `occurred_at` to the absolute scheduled step instant. Their text names the escalation step (one-based for readers). Message content is frozen at confirmation/reopening; it is not refreshed at execution. UUID/body remain stable across retries and distinct between steps.

The UUID, event time and message snapshot are durable and byte-stable across attempts; timestamp/signature are renewed at each webhook POST. Local event/row deduplication, serial admission and fencing prevent duplicate domain effects from ordinary repeated events or concurrent queue deliveries. The external provider and PostgreSQL cannot commit atomically: a provider may accept a message before the worker crashes or loses its response. Retrying that uncertain attempt can duplicate a remote email/Slack/Telegram message. This is an explicit at-least-once delivery limit, not an exactly-once guarantee. Signed webhook receivers should apply the documented timestamp/signature checks and deduplicate effects by the signed UUID.

## Operations and remaining scope

No development migration or process restart is performed by tests. To use this source version, deliberately migrate the development database, then recreate the long-lived queue/scheduler processes so the new supervisor and shutdown grace take effect:

```sh
docker compose run --rm web php artisan migrate
docker compose up -d --no-deps --force-recreate queue scheduler
```

M5.04 adds no migration, dependency, lockfile, environment variable or key. Existing cycles prepared by M5.03 retain their original first-step-only audience; historical policy state is unavailable and is not reconstructed from today's policy. New openings and reopenings freeze all steps. Reload long-lived queue/scheduler code when adopting the change (`docker compose up -d --no-deps --force-recreate queue scheduler`); tests never restart development processes. The M5.03 migration above is still required if not applied previously. Monitor scheduling remains controlled by its existing separate switch. Timezone/DST quiet hours remain M5.05. No live provider delivery, remote CI, PHP 8.5 integration or real browser run is claimed by this document; see the actual results in TASKS.

Queue lifecycle behavior was checked against the installed Laravel sources and [Laravel queue documentation](https://laravel.com/docs/13.x/queues). Automated coverage is described in [TESTING.md](TESTING.md#incident-delivery-and-concurrency-m503).
