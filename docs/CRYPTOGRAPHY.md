# Cryptographic purposes and key rotation

SEC.11 separates application cryptographic protocols and adds an explicit, resumable data migration. This document is the deployment/retirement contract; task outcomes remain in [TASKS.md](TASKS.md). No real key or development database is changed by implementing or testing this feature. General backup infrastructure and restore acceptance remain part of M11.05.

## Keys and protocols

`PurposeKeys` strictly decodes the configured `APP_KEY` and `APP_PREVIOUS_KEYS` as raw/base64 roots of exactly 32 bytes, matching AES-256-CBC. It uses PHP's [HKDF-SHA256 implementation](https://www.php.net/manual/en/function.hash-hkdf.php): output 32 bytes, public salt `signalbox:key-derivation:v1`, info `signalbox:v1:<purpose>`. These strings are persistent protocol identifiers. Roots are validated without including their values in errors; application code never accepts an arbitrary purpose string.

| Purpose label | Consumer / canonical message |
|---|---|
| `laravel-encryption` | Official Laravel Encrypter, native model casts, Fortify, encrypted cookies. One cipher purpose shared by these native consumers; individual columns do not have separate encryption keys. |
| `signed-urls` | Native UrlGenerator signing/verification for account verification and status subscriptions. URL/path and expiry remain authenticated by Laravel. |
| `subscriber-email` | `<page ID>:<lowercase trimmed email>`; lookup candidates preserve uniqueness during rotation. |
| `subscriber-consent` | `<page ID>:<current password hash or empty>`; consent never becomes a visitor-session grant. |
| `page-session` | `<page ID>:<current password hash>`; session proof, upgraded only after successful authentication. |
| `channel-verification` | Existing `notification-channel-verification:<channel ID>:<six-digit code>` input. |
| `channel-destination` | Existing sorted configuration/type serialization, excluding only webhook `signing_secret`. |

New application digests are `sb1:<16 lowercase hex root ID>:<64 lowercase hex MAC>` (85 ASCII bytes). The root ID is the first 16 hex characters of SHA-256 of the decoded root; it is public metadata, never authorization. Verification authenticates the full purpose/message with configured keys, using constant-time MAC comparisons. New values for different purposes do not authenticate each other. Persistent digest columns are widened to 128 characters by `2026_11_16_000027_version_cryptographic_digests`; existing contents are left unchanged until authenticated migration.

The native Laravel cipher is preserved rather than reimplemented. It writes with the current derived encryption key and reads with the configured current/previous derived keys. During upgrade only, `CRYPTOGRAPHY_ALLOW_LEGACY=true` also enables original raw-key ciphertext, original URL signing keys and the original 64-character application HMACs. This explicit switch defaults to true for compatibility; new writes always use derived keys. [Laravel's rotation contract](https://laravel.com/docs/13.x/encryption#gracefully-rotating-encryption-keys) supplies multi-key decryption; the application extends the configured keyring to its digests and URL signatures. Previous keys alone never rewrite persistent values.

`CryptographyServiceProvider` overrides only the native encrypter's key source and UrlGenerator key resolver, before application consumers resolve them. The installed Laravel 13.40 UrlGenerator uses the first resolver key when signing and tries configured keys when validating. Package code is unchanged. `APP_PREVIOUS_KEYS` remains available with legacy disabled, for previous **derived** keys. Rebuild configuration and restart every long-lived process after a key/configuration deployment; already-resolved ciphers intentionally do not hot-reload secrets during a request.

Webhook channel signing secrets, Stripe/Reverb credentials, password hashes, random hashed invitation/heartbeat/Sanctum tokens and DNS challenges keep their independent native protocols. No attempt is made to change external signing contracts or vendor serialized-closure protocols. Application queues use scalar class jobs; drain any application/package serialized closures before root rotation.

## Compatibility and retained links

Runtime lookups search current/previous accepted email digests under the existing Page lock, preventing duplicate subscribers across key versions. A stored consent must authenticate the live page password. Snapshot queries and delivery admission accept equivalent consent/destination digests while historical keys remain configured. Notification coalescing and resolution lookups group equivalent destination digests across the same rotation window; delivery attempt fences, quota checks and domain deduplication are unchanged.

The operational migration only converts a consent/destination when it still matches its current source. Stale values become stable `retired:<SHA-256 of old opaque digest>` markers, which cannot authorize a current proof. It does not change generation, verified/unsubscribed state, message UUID, recipient, payload text or attempt fence. Missing key identifiers stop migration; they are never interpreted as proof that a consent is obsolete. A failed mailbox identity check or uniqueness collision also stops rather than merging recipients. All necessary historical roots must be retained, including for original unversioned values whose key ID was never stored.

Short pending verification codes cannot be reconstructed from their MAC. The keyring continues validating them until explicit confirmation or their original expiry; no new code/email is issued by migration. The command reports live codes requiring a previous key or original-format support and clears only expired pending codes in mutating mode. Existing wrong-code counters and expiry enforcement remain.

Unsubscribe URLs have no time expiry. Before retiring any signing key, migration reconstructs their exact signed relative paths for every configured URL key and **current recipient generation**, storing only SHA-256 capability hashes in `status_subscription_link_grants`. A unique hash index serves lookups; a composite tenant/page/subscriber foreign key and index govern ownership/deletion. No key, mailbox or plaintext URL is stored. The route accepts a normal valid Laravel relative signature first; otherwise only an exact retained **unsubscribe** capability can reach the controller. Extra query parameters, changed identities/actions and altered signatures fail. GET remains inert, POST still requires CSRF, and the domain rechecks generation. Resubscription invalidates old capabilities even when their retained ledger rows still exist; deleting a subscriber cascades them. Historical grants are intentionally retained for the lifetime of that consent generation, across later key retirements. They cannot verify a mailbox, unlock a page or authorize another route.

## Operational command

```sh
php artisan security:rotate-data --check --batch=100
php artisan security:rotate-data --batch=100
php artisan security:rotate-data --check --batch=100
```

These are **operator commands for a prepared deployment**, not instructions to run automatically against the current developer environment. Batch size is 1–1000 identities. `--check` counts required changes without writing data. Mutating mode commits bounded row/domain transitions and can be resumed after interruption. Output contains aggregate counts only; failures do not print SQL, addresses, ciphertext, capabilities, keys or nested exception diagnostics. A session-level PostgreSQL `pg_try_advisory_lock(186974, 11)` rejects simultaneous CLI runs immediately and releases in `finally`. This serialization does not coordinate different key configurations across running application versions: deployment quiescence remains required.

Ciphertext rows lock only the row they rewrite and preserve exact decrypted bytes, including Fortify's serialized strings and native cast JSON. Domain digest migration locks Team → Page → Subscriber, or Team → delivery for destinations; no lock is held across external I/O. Identity scans are chunked; the complete database is not held in one transaction. A failure may follow earlier committed work: keep every key, repair the underlying problem without disclosing protected values, and rerun. A successful second mutating run/preflight is a no-op for current values and existing grants.

The encrypted-field registry is explicit:

| Table | Fields |
|---|---|
| `users` | `two_factor_secret`, `two_factor_recovery_codes` |
| `monitors` | `url`, `headers_json`, `body` |
| `notification_channels` | `config_json` |
| `notification_logs` | `message_payload` |
| `status_page_subscribers` | `email` |
| `status_page_deliveries` | `payload` |

Add every future application encrypted column to this registry and its retirement tests. Redacted outcomes, passwords, random token hashes and ciphertext-free audit data are not re-encrypted.

Exit zero means the requested migration/preflight succeeded with no live codes requiring historical support. In `--check`, it additionally means zero required changes. Exit one means invalid options, another migration, unreadable/conflicting data, required preflight changes or live historical codes. Counts alone do **not** prove that URL/cookie lifetimes elapsed, backups are restorable or writers are quiescent; the command says so explicitly. The command is global operator tooling with explicit SQL/tenant ownership; it has no HTTP route.

## Initial adoption without a root change

1. Securely back up the database and its matching key/configuration set together; verify access to restore material. Keep backups encrypted and access-controlled outside the repository. A snapshot without its required roots is insufficient.
2. Prepare schema expansion, code and configuration deployment. Quiesce HTTP writers, scheduler, workers and Reverb through the normal deployment process; drain in-flight attempts. Do not run old and new crypto writers together. Apply the new additive migration before serving new code. Keep the existing `APP_KEY`, retain any previous roots, and enable legacy support. No key generation is needed for adoption.
3. Deploy the provider/consumers together and rebuild configuration consistently. Run mutating migration, then preflight. Investigate a nonzero result without retiring keys. Old pending codes may require waiting until confirmation/original expiry, then another mutating run and check. Once every writer runs the same new code/configuration and data is readable, traffic/processes can resume **with compatibility still enabled**; retention windows below elapse online. Retain all roots until every required retirement step completes.
4. Keep legacy verification for finite links until their original configured lifetime has elapsed since old issuance stopped: subscription verification is one hour; account verification uses `auth.verification.expire` (Laravel default 60 minutes). Do not shorten existing expiries. Unsubscribe paths are retained through the grant ledger instead.
5. Account for client cookies and session proofs as well. Active requests reissue derived-key cookies and upgrade authenticated session proofs, but dormant original-format cookies cannot be rewritten server-side. Set a retention window covering the relevant session/remember-cookie policy, or explicitly plan reauthentication for remaining dormant clients. The data preflight does not certify cookie retirement. Do not silently assume the usual session lifetime covers a longer remember cookie.
6. After these conditions are satisfied, use a separate short cutover to disable `CRYPTOGRAPHY_ALLOW_LEGACY` consistently and reload all code/configuration. Preflight again, then smoke-test normal login/2FA, protected pages, subscriber uniqueness, verification/old unsubscribe links and pending opening/resolution deliveries. Resume traffic/processes through the deployment process. Any actual cookie reauthentication is an operator cutover decision; none is performed by this implementation.

## Later root rotation and retirement

1. Establish a paired backup and quiesce/drain writers as above. Generate a fresh 32-byte root through the deployment's secret-management process; never pass it as an Artisan argument, commit it or emit it into this command's output.
2. Set the new root as `APP_KEY` and put the prior current root plus every still-needed earlier root in `APP_PREVIOUS_KEYS`. Preserve the current legacy flag while any original-format data remains. Deploy the same complete configuration to all instances and reload every process before resuming writers.
3. Run migration/preflight with **all** required old roots still present. This rewrites old ciphertext/digests to the new current subkeys, preserves consent validity, and retains old derived unsubscribe capabilities as well as original ones when enabled. Codes still requiring old roots must confirm/expire first. Re-run to obtain zero outstanding work. Resume compatible readers/writers with the complete keyring once configuration is consistent; retention windows elapse online.
4. Preserve historical signature/cookie readers for their declared lifetimes or an explicitly planned reauthentication cutover. New URL signatures use the new key immediately; finite old signatures keep their original deadlines. Keep backups paired with the complete root set needed by those backups, even after live retirement.
5. Only after data, code, signature/cookie and operational checks are satisfied, perform a separate short cutover to remove the retired root from live `APP_PREVIOUS_KEYS`, rebuild configuration/reload all instances and repeat preflight/smoke tests. Validate old unsubscribe links through their retained capabilities, then resume traffic. Missing required roots must be restored immediately; never classify unreadable data as disposable.

No real root, `.env`, service state or development database is altered by tests. The release requires normal schema/code/configuration adoption by the operator.

## Failure, rollback and restore

While historical roots remain configured, stop on any nonzero migration result and keep the new code plus full keyring available; completed rows remain readable, and rerunning is supported. If the new root is unavailable during rollback, the already rewritten data requires restoring the matching encrypted database snapshot and its original complete key/configuration set together. Merely reverting `APP_KEY` or code does not reverse new ciphertext/digests. Freeze new writes first; reconcile any work after the snapshot through the deployment recovery process. Restore old code only with its old-format snapshot, never against newly versioned digests.

The migration's `down` does not narrow expanded columns; that would truncate authenticated values. Dropping the retained grant ledger after signing keys have been retired would invalidate historical unsubscribe capabilities. Preserve it unless restoring the paired pre-adoption state; do not treat schema rollback alone as crypto rollback.

Automated tests exercise original/derived historical reads, migration/idempotence, exact-byte rewrap for **all** registered fields, paired synthetic row/key restoration, preserved 2FA recovery values, old unsubscribe links after complete key retirement, expiry, revocation/tenant tampering, missing keys, invalid identities/ciphertext and simultaneous operator runs. This is proof of the application protocol on isolated PostgreSQL, not a production snapshot/secret-manager restore drill; full infrastructure backup/restore acceptance remains M11.05. No throughput, multi-node deployment or live cookie-retirement guarantee is claimed.
