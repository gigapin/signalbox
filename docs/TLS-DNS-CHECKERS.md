# TLS and DNS checkers (M3.04)

M3.04 adds verified TLS acquisition, DNS record checks and a bounded replacement for the outbound guard's former `dns_get_record()` implementation. It leaves `CheckWorkConsumer` unbound and scheduling disabled. Heartbeat is implemented separately in [M3.05](HEARTBEATS.md); result persistence, monitor summaries, incidents and notifications remain subsequent tasks.

## Boundaries and admission

`NetworkCheckCoordinator::run()` accepts a bounded list of `CheckInput` objects containing TLS or DNS configurations. It returns one `CheckOutcome` per execution ID, in input order even when completion order differs. The same explicit tenant context and maximum batch size of ten used by HTTP apply here. The entire batch is validated before network traffic: distinct execution identities, tenant ownership, supported concrete configurations, timeout bounds and local configuration syntax. A private input is never a queue payload or a public projection.

A single coordinator shares admission, concurrency, deadlines, safe failures and resource ownership between these two protocols. This replaces the plan's separate coordinators to avoid duplicated orchestration. `TlsStrategy` and `DnsStrategy` remain separate pure evaluators; `TlsTransport` and `DnsTransport` are independently replaceable I/O boundaries. Unsupported variants are rejected explicitly rather than defaulting to another checker.

`AsyncCheckRuntime` owns a fresh event loop for each synchronous invocation. All acquisitions are started before waiting. The loop drains completion and cancellation work, including ReactPHP DNS's short TCP idle-close timer. A generic completion holder preserves result types without local PHPDoc assertions. Programming failures reject the batch and cancel pending work; recognized transport failures produce only the affected observation's down result. No database writes occur.

## TLS acquisition and expiry

The coordinator resolves A and AAAA concurrently through the system nameserver, checks **every** answer using `OutboundDestinationGuard::authorizeResolvedEndpoint()` and passes only authorized pins to the TLS adapter. A literal host bypasses DNS but still passes the address policy. Local names and blocked literal addresses fail before I/O. The first authorized answer is used without a second attempt or DNS re-resolution.

`ReactTlsTransport` connects to that literal IP through an IP-only TCP connector while retaining the original peer name for SNI and certificate verification. It verifies the trust chain, peer name and validity period, refuses self-signed certificates, captures the leaf certificate and immediately closes the connection without sending application data. IP targets require the matching IP certificate identity. The normal binding uses PHP's system CA store. An optional constructor-injected CA file is infrastructure-owned and supports synthetic tests; monitor configuration cannot supply it or disable verification. An unavailable explicitly injected bundle is a setup exception.

`timeoutMs` covers the entire TLS observation: DNS, TCP and handshake. The handshake receives only the unused budget; completion at the exact deadline fails. Latency measures this whole acquisition, unlike the existing HTTP network-only metric. A local policy refusal before I/O has null latency; measured fast operations may legitimately have zero milliseconds.

`TlsStrategy` uses the actual UTC observation instant, not the scheduled reference instant. Expiry at or before that instant fails with `tls_failed`. A null warning window performs ordinary validity checking only. A configured warning window fails with `tls_expiring` when `expiresAt <= observedAt + expiryWarningDays * 86400 seconds`. Zero therefore adds no early-warning interval. There is no separate warning status.

`CheckOutcome::tlsExpiresAt` is an optional UTC instant added after the existing constructor parameters. It is present only after a verified handshake, including a pre-expiry failure. No certificate, subject, SAN list, destination or raw transport diagnostic is returned. `Monitor.ssl_expires_at` is not updated in this task; the future runner must persist metadata under the ledger fence.

## DNS acquisition and comparison

A null monitor resolver selects the first valid literal nameserver in the system configuration. System resolvers may be internal infrastructure, including Docker's embedded resolver. They are trusted configuration rather than user-controlled destinations. A custom resolver must be a literal address approved by `authorizeResolver()`, on port 53. There is no implicit public fallback or alternate resolver retry. Missing system configuration raises a setup exception rather than reporting a target outage.

`ReactDnsTransport` composes uncached UDP and TCP executors explicitly. One timeout wraps the complete operation. A truncated UDP response continues over TCP within that same budget; ordinary failures are never retried. Queries use absolute names, without `/etc/hosts`, search suffixes or application caching. The upstream resolver may have its own cache. Invalid UDP packets that cannot be parsed or have an unexpected transaction ID are ignored by the vendor transport until a valid response or timeout, rather than trusted as answers.

`DnsLookup` verifies response/question identity and uses only IN-class answer records for the queried owner and its alias chain. Authority and additional sections cannot inject comparison values. For A/AAAA/MX/TXT, aliases may be followed inline or through another query, sharing one deadline. Cycles, conflicting aliases and chains longer than eight hops fail. CNAME monitors compare the direct alias without following it. DNS response addresses are comparison data and are never contacted; a private A record can therefore match a DNS expectation while remaining forbidden as a TLS/HTTP destination.

| Family | Canonical comparison value |
| --- | --- |
| A / AAAA | Canonical address of the requested family. |
| CNAME | Lowercase normalized hostname without a terminal dot. |
| MX | `priority hostname`, with canonical hostname and `0 .` for null MX. |
| TXT | Concatenated segments of each individual record, preserving case, whitespace and binary bytes. Separate records remain separate values. |

The complete observed set must exactly equal the normalized expected set, ignoring order, duplicates and TTL. Extra records fail. No data/NXDOMAIN produces `dns_missing`, different nonempty sets produce `dns_mismatch`, server/transport errors produce `dns_response_failed`, invalid payloads/alias chains produce `dns_malformed`, and an expired budget produces `timeout`. DNS failure messages contain no names, record values, resolver addresses or raw vendor exceptions.

## Bounded HTTP guard resolution

`HostResolver::resolve()` now accepts an optional millisecond budget. `SystemHostResolver` uses the same DNS adapter for concurrent A/AAAA queries and aliases. The default `monitor-checks.resolution_timeout_ms` is **1000 ms for the whole resolution**, not per family or alias. Both families must complete successfully; a recognized DNS failure returns no addresses rather than authorizing partial results, and a timeout propagates as a safe timeout outcome. Existing fake resolvers retain their immediate deterministic behavior.

HTTP keeps its previous definition of network latency and cumulative hop budget. DNS is now independently bounded for each guard authorization, including each redirect. The HTTP coordinator still authorizes observations sequentially before each pool round, so this change does not claim concurrent HTTP DNS authorization or an end-to-end HTTP wall-clock deadline. Worker lifetime and runner integration remain M3.06.

## Verification and compatibility

- `NetworkCheckStrategyTest`: exact expiry boundaries, nullable/zero warning windows, canonical DNS expectations and exact set/TXT comparison.
- `NetworkCheckCoordinatorTest`: tenant and full-batch admission, safe policy refusals, address pinning, alias handling, malformed/negative DNS responses, shared deadlines, completion ordering, programming-error cancellation and HTTP resolution regressions.
- `NetworkCheckTransportTest`: real trusted/untrusted/expired/hostname-mismatched TLS certificates, TCP refusal, stalled handshakes, two-client/two-query barriers, UDP-to-TCP continuation and timeout isolation.

Certificates, trust anchors and private keys are generated in temporary test storage and removed after bounded child servers stop. No external nameserver or customer endpoint is contacted. Full verification is `./scripts/verify.sh`; actual results and remaining limits are recorded in [TASKS.md](TASKS.md).

OpenSSL is an explicit platform requirement. ReactPHP DNS, event-loop, promise, promise-timer and socket are now direct requirements at their existing locked versions; see [DEPENDENCIES.md](DEPENDENCIES.md). No migration, environment variable, development key, route or application-process change is required.

Official transport references: [ReactPHP DNS](https://reactphp.org/dns/), [ReactPHP Socket](https://reactphp.org/socket/) and [PHP TLS context options](https://www.php.net/manual/en/context.ssl.php).
