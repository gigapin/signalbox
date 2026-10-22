# Monitor model and typed configuration

M2.01 introduces persistence for the five monitor types, building on [TENANCY.md](TENANCY.md) and ADR-002/003/006/007/010 in [ARCHITECTURE.md](ARCHITECTURE.md). It does not add routes, monitor policies, CRUD, network requests, check execution or scheduling. [TASKS.md](TASKS.md) records actual verification evidence.

## Ownership and initial state

Monitor uses BelongsToTeam, including its fail-closed query, write, reload and restoration behavior. Team::monitors() remains scoped: traversing another team's relation does not grant its data, and writing through that relation under the wrong context is rejected. User/Team membership resolution remains outside the monitor scope. The incremental migration references teams with cascade-on-delete; deleting a monitor never deletes its team.

Only name permits ordinary mass assignment. Configuration is assigned through applyConfiguration; ownership and all cadence, pause, scheduling and observation fields require trusted explicit assignment. This is a persistence boundary, not a substitute for the authorization and limit actions introduced in M2.04. Raw/bulk writes retain the trusted-operation limitations described in TENANCY.

| Shared field | Meaning and initial state |
|---|---|
| type | http, keyword, tls, dns or heartbeat; required before save |
| regions_json | Canonical nonempty region selection; default eu only |
| interval_seconds | Explicit positive cadence in seconds; no production default |
| timeout_ms | Explicit positive timeout in milliseconds; no production default |
| confirmation_threshold | Positive failure threshold; default 3 |
| is_paused | Voluntary pause; default false |
| is_plan_suspended | Plan suspension, independent of voluntary pause; default false |
| last_status | unknown, up or down; initially unknown |
| consecutive_failures | Nonnegative summary, initially zero; not authoritative per-region incident state |
| last_checked_at | Last observation instant; initially null |
| next_check_at | Next UTC slot; null means not initialized |
| ssl_expires_at | Last observed certificate expiry; initially null |

All timestamps use PostgreSQL timestamptz and immutable date casts. Monitor normalizes assigned instants to UTC before Laravel formats them for persistence, preserving offsets through DST boundaries. The model does not initialize or advance next_check_at, reset regional sequences, create pause history or reconcile a plan. Those transitions belong to their owning actions and engine tasks. Both pause flags may be true; clearing one never clears the other.

The database indexes (team_id, id), plus (next_check_at, id) only where next_check_at is present and neither pause flag is set. These support tenant lookup and future due-work selection; no scheduler or throughput claim is implied.

## Configuration variants

MonitorConfiguration identifies a real variant through type(): MonitorType. Final readonly DTOs contain no database, HTTP request or network dependencies. The mapper owns dedicated-column translation; Monitor::configuration() reconstructs a structurally valid DTO, and applyConfiguration() changes model attributes without saving.

| DTO | Persisted configuration |
|---|---|
| HttpMonitorConfiguration | url, method, headers_json, body, expected_status, follow_redirects |
| KeywordMonitorConfiguration | Composed HttpMonitorConfiguration, expected_keyword, keyword_mode |
| TlsMonitorConfiguration | tls_host, tls_port, tls_expiry_warning_days |
| DnsMonitorConfiguration | dns_name, dns_record_type, dns_expected_values, dns_resolver |
| HeartbeatMonitorConfiguration | heartbeat_grace_seconds; cadence uses shared interval_seconds |

HTTP methods are GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS. Defaults are GET, empty headers, null body, expected status 200 and redirects disabled. The destination is required. Keyword mode is present or absent, default present; exact keyword bytes are retained, including meaningful whitespace. Keyword text must not be empty. HTTP and keyword share transport settings through composition, without parallel transport implementations.

TLS requires an explicit host and TCP port 1–65535. A nullable nonnegative warning window in days defaults to null, meaning ordinary validity/expiry checking without an additional pre-expiry window. The DTO provides no switch to disable certificate, chain or hostname verification. DNS supports A/AAAA/CNAME/MX/TXT, a nonempty list of nonempty expected values and an optional nonempty resolver. A null resolver delegates selection to the future protected infrastructure; no resolver is contacted here. Values are not normalized into a speculative DNS response format. Heartbeat tolerance is an explicit nonnegative number of seconds; receipt timestamps, credentials and deadline evaluation remain M3.05.

Structural validation rejects incomplete variants, malformed header maps, invalid primitive ranges, invalid DNS lists and unsupported configuration implementations. Replacing a configuration clears every irrelevant configuration column before applying the new variant. It does not persist automatically or invalidate cached observation state: future mutation actions must perform the appropriate transition and scheduling changes. No DTO fallback is substituted when stored required fields are absent.

This structure deliberately preserves the specification's dedicated columns while extending TLS, DNS and heartbeat fields. DNS expected values and execution regions use JSONB; a canonical MonitorRegions value object rejects empty, duplicate or unsupported regions, and serializes to scalar identifiers. Selecting us then eu normalizes to eu then us; raw SQL must persist that canonical ordering. Only eu and us exist, and represent simulated queues rather than real geographic deployments.

## Encryption and serialization

URL, HTTP headers and body use Laravel encrypted/encrypted:array casts with the application key. Their columns use text because encrypted payloads are not queryable JSONB; headers_json retains its logical JSON meaning. This is an explicit storage refinement, not plaintext secret storage or a new dependency. Null and empty body remain distinct, and decrypted values are available only through trusted model/DTO access.

All type-specific configuration columns are hidden from automatic Eloquent array/JSON serialization, including keyword, DNS and TLS fields. Future authorized API/Inertia projections must select safe fields explicitly. This does not make raw attributes, DTOs, debug dumps or deliberately unhidden fields safe to publish. No raw URL/header/body payload is added to events or logs. Caches and queue payloads must follow the scalar-identifier tenancy contract rather than serializing configuration-bearing models.

DTO construction is not destination authorization. M2.02 adds URL/host syntax, header rules and operational bounds at the [input boundary](MONITOR-INPUT.md); trusted model/DTO construction retains its structural contract. DNS resolution, redirect checks, SSRF defenses and secret redaction are defined in [OUTBOUND-SECURITY.md](OUTBOUND-SECURITY.md) (M2.03); every future checker must connect through that guard and expose configuration only through its projection.

## Database invariants and fixtures

PostgreSQL independently constrains supported types/statuses/methods/DNS families, nonempty labels, positive cadence/timeout/threshold, nonnegative failure counts, canonical regions and the required/irrelevant columns for each variant. DNS expectations must be a nonempty JSON array of nonempty strings. TLS ports/warning days and heartbeat tolerance retain their structural ranges. Ciphertext cannot be semantically validated by SQL; typed model configuration validates decrypted values before ordinary saves.

These constraints do not enforce per-plan quotas/frequencies or the final maximum timeout, nor do they implement SSRF checks. The sole summary failure counter does not replace the per-region state, ordering and idempotence required by ADR-007. Future scheduler/check/incident work must add its actual persistent execution state rather than infer it from the summary.

MonitorFactory defaults to a synthetic HTTP configuration and offers explicit http/keyword/tls/dns/heartbeat states. It supplies fixture-only cadence 300 seconds and timeout 5000 milliseconds, uses reserved example.invalid and documentation addresses, and requires an existing explicit tenant context even for make(). It never provisions a team or performs a request. No monitor is added to the development seeder by this task.

Apply the new migration deliberately to the intended environment when needed; automated checks migrate only the guarded test database. The existing identity rollback test now reverses/reapplies the dependent monitor migration as well, because its FK prevents dropping teams first. Baseline account identities remain intact.

## Verification and next tasks

MonitorConfigurationTest exercises all five variants, meaningful null/empty values, primitive boundaries and region normalization/rejection without infrastructure. MonitorModelTest exercises typed round trips, encrypted raw storage, hidden serialization, configuration replacement, UTC/DST instants, raw-SQL constraints, indexes, rollback/cascade, factories and real tenant queries/relations/writes/reloads. Existing M1 authorization, worker, CLI and concurrency suites remain required; no HTTP route has been introduced to the authorization catalogue.

```sh
docker compose run --rm php-test composer test -- --filter='MonitorConfiguration|MonitorModel|TeamIdentity' --fail-on-skipped --fail-on-warning
./scripts/verify.sh
```

M2.02 provides [Form Request/input validation](MONITOR-INPUT.md), complete typed candidates and explicit patches. M2.03 adds [outbound security](OUTBOUND-SECURITY.md); M2.04 adds [authorized CRUD, transitions and centralized concurrent plan limits](MONITOR-CRUD.md); M2.05 adds UI/search and M2.06 completes their integration coverage. The model and input foundation do not declare these later acceptance criteria complete.
