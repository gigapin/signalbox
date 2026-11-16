# Bounded public database admission (SEC.09)

Anonymous consent and heartbeat requests use `PublicIngressTransaction` to keep lock contention local and short. Work status and actual verification results are in [TASKS.md](TASKS.md). No dependency, migration or environment variable is added.

## Ownership and lock order

| Entry | Locks and authority |
|---|---|
| Status-page subscription POST | Live public page/password authorization, Page → Subscriber; no Team row lock |
| Signed verification/unsubscribe GET | Signature and current page/recipient/generation reads; no row locks or consent writes |
| Signed verification/unsubscribe POST | Signature, Page → Subscriber → unclaimed pending deliveries; no Team row lock |
| Heartbeat POST | Digest-only owner discovery, Team `FOR SHARE` → Monitor `FOR UPDATE` → heartbeat state `FOR UPDATE`; fresh token and central eligibility checks |
| Internal incident publication | Existing Team/Monitor/Incident/Update order, then Page `FOR UPDATE` before selecting the consenting audience |
| Email attempt admission | Existing Team → Page → Subscriber → Delivery order, with all consent checks before committing the lease |

Consent needs a stable page/password and mailbox generation, not a locked Team aggregate. The Page lock serializes a first subscriber's insertion and mailbox-generation rotation, preserving unique recipients and deduplicated verification intents. Existing composite foreign keys bind subscribers to pages and deliveries to subscribers/public entries; no direct Team foreign key forces anonymous inserts to reacquire the Team row. A request on one page can proceed while another page or unrelated team management is locked.

Publication now acquires that same Page lock before its audience query. Confirmation/revocation and publication are ordered at this boundary, so a just-committed confirmation is visible to a later snapshot and a failed publication cannot leave a partial public entry/outbox. The email ledger already acquires Page before Subscriber and Delivery, so it remains consistent with anonymous revocation without a reverse dependency on Team. An already-admitted SMTP call remains subject to the documented [delivery boundary](STATUS-PAGES.md#delivery-and-recovery).

Heartbeat receipt admission still depends on a stable entitlement and deterministic monitor quota. A shared Team lock permits concurrent receipts for different monitors while excluding the exclusive Team locks used by plan/override/quota and lifecycle changes. Removing the Team lock entirely would weaken that invariant. Monitor/state locks continue to serialize same-monitor receipts, credential rotation, pause and arming-generation changes. PostgreSQL's [row-lock compatibility](https://www.postgresql.org/docs/16/explicit-locking.html#LOCKING-ROWS) defines these relationships. All private reads remain inside the credential's explicit TenantContext; signed consent coordinates are checked through the same tenant scope and composite owner keys as before.

Scanner GETs only read generic capability state. A concurrent edit may make that form stale, but POST revalidates under locks before changing consent. No address, private page content or tenant metadata is returned by capability pages.

## Timeout and transaction boundary

`PublicIngressTransaction::LOCK_TIMEOUT_MS` is **250 milliseconds per lock acquisition**. The wrapper reads the caller's effective PostgreSQL setting in milliseconds and uses the lower nonzero bound. A caller with a 25 ms limit keeps it; an unbounded or two-second caller uses 250 ms only during this operation. There is one attempt, without automatic retries or notification/check provider calls in the callback. The heartbeat's existing cache-backed rate-limit operations remain inside its admission boundary.

The setting is transaction-local. A successful nested call explicitly restores the prior value before returning, because releasing a savepoint does not undo `SET LOCAL`. A failed SQL statement first rolls back the transaction/savepoint, which restores the earlier setting and makes the outer connection usable again. It does not try to issue restoration SQL while the transaction is aborted. Domain exceptions take the same rollback path. These are PostgreSQL's documented [SET and savepoint rules](https://www.postgresql.org/docs/16/sql-set.html); they avoid the timeout leak recorded in FX.01.

The timeout is a wait bound, not a total HTTP/SQL execution deadline. It does not bound CPU time, PostgreSQL connection latency, Redis/session I/O or the sum of several separate lock waits. PostgreSQL documents that distinction for [lock_timeout](https://www.postgresql.org/docs/16/runtime-config-client.html#GUC-LOCK-TIMEOUT). Private jobs keep their existing timing policy; the bounded wrapper is used only at the public consent/heartbeat service boundary.

`TenantContext::run` and the new transaction wrapper declare their callbacks as immediately invoked for static analysis. Both execute synchronously and propagate errors; the [PHPStan callable annotation](https://phpstan.org/writing-php-code/phpdocs-basics#callables) documents this rather than suppressing exception diagnostics. There is no TenantContext runtime behavior change.

## Public response contract

Only PostgreSQL SQLSTATE **55P03** is translated to `PublicIngressBusy`, after rollback. The new exception retains no original SQL exception, credential, email or bound values. The HTTP boundary maps it to **503**, `Retry-After: 1`, with the generic message `The service is busy. Please try again shortly.` Existing privacy/security middleware still applies; heartbeat responses remain session-free.

A refused request records no receipt, consent transition or email intent. A timeout after the unsubscribe write but before pending-delivery suppression rolls back both operations. Clients can retry after contention clears; a retried heartbeat uses the current token, plan, pause state and generation. An inactive but valid heartbeat still returns 204 without recording, and an invalid/revoked token still returns 404. No successful receipt is fabricated to hide contention. Other database failures retain their existing error handling.

## Verification and adoption

`PublicIngressTransactionTest` checks the lower timeout bound, restored successful nesting, domain/SQL rollback, preserved outer work, and real two-connection contention at root and savepoint levels. `PublicIngressContentionTest` checks independent pages and monitors, scanner GETs during writes, generic/private HTTP 503 responses, late outbox-lock rollback, fresh downgrade/revocation admission, and publication/delivery ordering around uncommitted consent. All rows live only in guarded test PostgreSQL, with no real provider calls.

The existing subscription concurrency probe now holds Page for subscription races; publication/delivery retain their existing Team barriers. Heartbeat HTTP race tests use a 10 ms curl select interval so the probe observes the database waiter before the 250 ms public deadline. This changes transport polling in tests, not the production bound. Keep PostgreSQL on its real clock for lock-timeout tests, as in the existing date-independence script.

```sh
docker compose run --rm -e COMPOSER_ROOT_VERSION=dev-main php-test vendor/bin/pest \
  tests/Integration/PublicIngressTransactionTest.php tests/Integration/PublicIngressContentionTest.php \
  tests/Integration/HeartbeatTest.php tests/Integration/HeartbeatConcurrencyTest.php \
  tests/Integration/StatusSubscriptionsTest.php --fail-on-skipped --fail-on-warning
./scripts/verify.sh
```

Adopt through the normal PHP deployment/reload procedure. No package install, schema operation, new environment value or key is required. Verification never migrates development storage or restarts development services. External heartbeat clients should treat 503 as a refused attempt and may honor `Retry-After`; no automatic queue/domain retry is introduced.
