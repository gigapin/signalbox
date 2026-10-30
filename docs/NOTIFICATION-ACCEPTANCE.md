# Notification acceptance matrix (M5.06)

This document maps the M5 exit criteria to executable evidence. Product contracts remain in [NOTIFICATIONS.md](NOTIFICATIONS.md), [NOTIFICATION-DELIVERY.md](NOTIFICATION-DELIVERY.md) and [QUIET-HOURS.md](QUIET-HOURS.md); architectural tradeoffs remain ADR-012 in [ARCHITECTURE.md](ARCHITECTURE.md). Actual commands, counts and results belong in [TASKS.md](TASKS.md).

## Coverage and acceptance

| Criterion | Executable evidence | Behavior checked |
|---|---|---|
| Protected destinations and verified channels | `ChannelConfigurationParserTest`, `NotificationChannelsTest`, `NotificationAdapterVerificationTest`, `NotificationPagesTest` | Encrypted credentials, masked projections, code expiry/cooldown/exhaustion, destination-change reset, roles, foreign channels and no secret flashing |
| Email, Slack, Telegram and signed webhooks | `NotificationAdaptersTest`, `NotificationAdapterVerificationTest`, `NotificationDeliveryMatrixTest` | Real adapter selection with fake SMTP/HTTP/DNS, correct provider acknowledgements, bounded requests, no provider call under a delivery transaction |
| SMS remains an explicit stub | `ChannelMessageTest`, `NotificationAdaptersTest`, `NotificationDeliveryMatrixTest` | Recorded suppression, no external request or successful delivery, no recovery summary after only stubbed opening/escalation |
| Atomic preparation and frozen audiences | `IncidentNotificationDeliveryTest`, `NotificationConcurrencyTest` | Incident/timeline/outbox rollback together; event marker even for empty audience; policy edits/replays do not add recipients; independent sessions prepare one row per step |
| Local deduplication and stable retries | `IncidentNotificationDeliveryTest`, `NotificationConcurrencyTest`, `NotificationDeliveryMatrixTest` | Duplicate events/envelopes preserve row/message identity; one live attempt per incident/channel; late/expired fences cannot overwrite outcomes; recipient UUID/body stable while queue-attempt tokens change |
| Grouping, recovery and reopening | `IncidentNotificationDeliveryTest`, `NotificationDeliveryMatrixTest` | Fast transitions suppress unaccepted work; one final summary per actually notified channel; obsolete queued cycles do not send; separate incidents never cancel/combine each other's work |
| Absolute escalation delays | `IncidentNotificationDeliveryTest`, `NotificationDeliveryMatrixTest` | Explicit five-minute time travel, exact due-time boundaries, no early execution even when a delayed fake envelope is manually invoked, fresh schedule on reopening and cancellation on recovery |
| Provider unavailability and retry budget | `NotificationAdaptersTest`, `IncidentNotificationDeliveryTest`, `NotificationDeliveryMatrixTest` | Safe SMTP/HTTP errors, durable backoff, recovery command republishes only due rows, fresh queue fence per retry, eventual success or terminal exhaustion, no summary for recipients never notified |
| Webhook signature integrity | `NotificationAdaptersTest`, `NotificationDeliveryMatrixTest` | Exact-byte timestamp/body HMAC, altered body/key produce different independently calculated signatures, stable recipient ID/body through failed and successful attempts, current timestamp/signature per attempt |
| Maintenance suppression | `MaintenanceWindowTest`, `IncidentNotificationDeliveryTest`, `NotificationDeliveryMatrixTest` | Half-open UTC windows, suppression at initial/retry/expired-lease admission, already-admitted receipts preserved, no catch-up and future steps remain eligible |
| Quiet hours, midnight and DST | `QuietHoursTest`, `NotificationQuietHoursTest`, `IncidentNotificationDeliveryTest`, `NotificationConcurrencyTest` | Explicit IANA wall time, overnight boundaries, both repeated autumn hours, missing spring minutes, independent recipients/tenants, live rule changes observed after a Team-lock wait, verification still available |
| Combined suppression boundaries | `NotificationDeliveryMatrixTest` | Quiet end equals maintenance start; maintenance end equals quiet start; overlap preserves maintenance priority; both exclusive ends allow retry; no revived delivery or redundant summary |
| Tenant and worker isolation | `TeamAuthorizationMatrixTest`, `NotificationDeliveryConfigurationTest`, `IncidentNotificationDeliveryTest`, `NotificationDeliveryMatrixTest` | Route roles/foreign bindings, scalar envelopes, forged team/delivery pair refused, interleaved legitimate jobs restore the enclosing worker context and keep their own recipients |
| Recoverable publication and failed workers | `NotificationDeliveryConfigurationTest`, `IncidentNotificationDeliveryTest`, `NotificationConcurrencyTest` | Explicit Redis publication under sync default, scheduler wiring, production commit semantics, broker loss, per-tenant publication errors, bounded crashed attempts, safe failed hooks |
| Notification UI | `tests/frontend/Notifications.test.tsx`, `NotificationPagesTest`, `NotificationQuietHoursTest` | Management/read-only controls, configuration and verification, policy selection, delivery log, quiet-hour enable/edit/disable and announced validation errors |

The new M5.06 cases fill the cross-boundary gaps rather than repeating the existing adapter/unit assertions. `NotificationDeliveryMatrixTest` keeps lifecycle events and explicit production listener bindings active, uses the real `RedisNotificationWorkPublisher`, and intercepts its broker boundary with `Queue::fake()->serializeAndRestore()`. `Mail::fake()`, `Notification::fake()` and `Http::fake()` isolate recipient transport; the SMTP failure case substitutes a deterministic throwing mailer. DNS is supplied by `FakeHostResolver`. The tests run on PostgreSQL and use Laravel's controlled clock, including `travel(5)->minutes()`.

The helper executes serialized/restored envelopes through their actual middleware and handler pipeline. The fake queue neither runs jobs automatically nor implements broker delay timing: tests invoke jobs at selected instants and prove the ledger refuses early/obsolete envelopes. Retry publication goes through the real `notifications:dispatch` command. The suite does not claim to have exercised a live Horizon daemon or an external provider.

## Reproduction

The complete gate remains:

```sh
./scripts/verify.sh
```

Composer's `config.process-timeout` is 900 seconds per child process. During M5.06 the complete PostgreSQL suite exceeded the previous default of 300 seconds without a test assertion failure; the finite ceiling was raised to let the growing suite finish. This changes Composer subprocess deadlines; package resolution, lockfiles and application/queue timeouts are unchanged.

A focused notification acceptance run uses the isolated test runtime:

```sh
docker compose run --rm php-test vendor/bin/pest tests/Unit/ChannelConfigurationParserTest.php tests/Unit/ChannelMessageTest.php tests/Unit/QuietHoursTest.php tests/Feature/NotificationAdaptersTest.php tests/Feature/NotificationDeliveryConfigurationTest.php tests/Integration/NotificationChannelsTest.php tests/Integration/EscalationPoliciesTest.php tests/Integration/NotificationPagesTest.php tests/Integration/NotificationAdapterVerificationTest.php tests/Integration/IncidentNotificationDeliveryTest.php tests/Integration/NotificationDeliveryMatrixTest.php tests/Integration/NotificationQuietHoursTest.php tests/Integration/NotificationConcurrencyTest.php --fail-on-skipped --fail-on-warning
docker compose run --rm node npx vitest run tests/frontend/Notifications.test.tsx
```

Authorization and migration rollback coverage is also included in the complete gate. No test uses the development database, starts/stops application workers, changes keys or sends real recipient traffic. M5.06 introduces no migration, dependency, environment variable, route or domain change. Earlier migrations remain required to use the corresponding application features.

## Practical limits

`sent` means provider acceptance, not inbox/device delivery. Local deduplication and attempt fencing cannot make a provider and PostgreSQL commit atomically. A lost remote acknowledgement may cause a duplicate message on retry; webhook receivers can deduplicate by the signed stable UUID. This is the existing explicit at-least-once limit, preserved by the tests.

Suppression uses admission time and is terminal for the admitted due row; it does not schedule an end-of-window digest. Future escalation steps retain their own schedule. Already-admitted requests can record real acceptance until their lease expires. No cross-monitor digest, production SMS, real geographic probes or severity bypass is introduced.

Remote CI/PHP 8.5 integration, a real browser, real SMTP/Slack/Telegram/webhook delivery and live-worker operational smoke tests are not implied by passing this matrix. Their actual execution status remains explicit in TASKS. M6 broadcasting/dashboard work and M7 definitive uptime/SLA metrics remain separate milestones.
