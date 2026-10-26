# HTTP and keyword checkers (M3.03)

M3.03 implements protected concurrent acquisition and pure response evaluation for HTTP and keyword monitors. It does not bind `CheckWorkConsumer`, enable scheduling, load monitors, persist CheckResult, update summaries, open incidents or send notifications. [MONITOR-SCHEDULING.md](MONITOR-SCHEDULING.md) remains the delivery contract; the runner/result tasks must integrate these checkers deliberately.

## Interfaces and boundaries

| Component | Responsibility |
| --- | --- |
| `CheckContext` | Scalar team, monitor, execution, simulated region and scheduled reference instant. It identifies admitted work but grants no authority. |
| `CheckInput` | Trusted context, private typed configuration and positive timeout in milliseconds; never serialize into queues, logs or responses. |
| `HttpCheckCoordinator::run()` | Validate the whole batch, authorize connections, acquire HTTP and keyword responses together and return one result per execution ID in input order. |
| `HttpResponseStrategy` | Pure evaluation contract over typed input and a complete bounded `HttpObservation`. |
| `HttpStrategyResolver` | Explicit HTTP/keyword DTO and enum mapping; unsupported and inconsistent types throw instead of defaulting to HTTP. |
| `HttpStatusStrategy` | Exact expected HTTP status, including intentionally configured non-2xx statuses. |
| `KeywordStrategy` | Expected HTTP status first, then exact case-sensitive byte presence/absence. No regex, trimming, text decoding or Unicode normalization. |
| `CheckOutcome` / `CheckError` | Safe normalized observation and generic diagnostic with no URL, header, body, keyword, Location or raw exception. |
| `CheckClock` | Replaceable monotonic millisecond measurement; bound to `MonotonicCheckClock` in the provider. |

The coordinator requires the caller's explicit `TenantContext`. Every input must belong to that tenant and have a distinct positive execution identity. The entire list is checked before any DNS or HTTP work, so a malformed second input cannot cause partial traffic. An empty list returns an empty result. The trusted caller remains responsible for authorization, current plan/configuration admission and acquisition fencing; the coordinator deliberately does not query the database or interpret queue credentials.

`config/monitor-checks.php` sets a maximum batch size of **10** and a cumulative decoded response-body cap of **1,048,576 bytes per observation**. Larger batches are rejected; the future runner must split them explicitly. Invalid/missing configuration raises rather than silently removing a bound. Timeout minimum/maximum use the existing central `MonitorInputLimits` (currently 100–10000 ms), with no duplicate timeout configuration.

## HTTP acquisition and redirects

Both monitor types share `Http::pool()` for each redirect round. Execution IDs remain explicit keys, so completion order cannot associate a response with another monitor. Final results are returned in input order. Each observation has independent time and byte budgets; a failed connection becomes that observation's result and does not discard another observation. Redirect rounds wait for the current pool before preparing the next round, so an unrelated slow response can delay the start of another observation's redirect; it does not consume that observation's network budget. This is bounded batch concurrency, not a claim of streaming early results or load-tested throughput.

Before each connection, the [outbound guard](OUTBOUND-SECURITY.md) checks the destination and all DNS answers. Its options pin cURL to those addresses, disable environment proxies and automatic redirects, and now require a fresh connection without reuse: an older connection cannot bypass this hop's fresh pin. TLS verification stays enabled. Configured methods, header values and raw body bytes are preserved on the initial request; null body is sent without content. No domain/client retry is configured. Cookies from server responses are never implicitly collected or replayed.

When redirect following is disabled, the returned 3xx is evaluated against the configured status. When enabled, follow only 301, 302, 303, 307 and 308, requiring a usable Location and reusing `RedirectPolicy` for every authorization, hop limit and HTTPS-downgrade refusal.

| Response | Next method and body |
| --- | --- |
| 301 / 302 after POST | GET; drop the body and configured Content-* / Expect headers. |
| 301 / 302 after another method | Preserve method and body. |
| 303 except HEAD | GET; drop body and entity headers. |
| 303 after HEAD | Preserve HEAD. |
| 307 / 308 | Preserve method and body. |

Across an origin change, remove **all** configured headers and never restore them on a later return to the original origin. If the rewritten request still carries a nonempty body, refuse the cross-origin hop instead of leaking private bytes. POST → GET rewrites therefore may cross origins after the body has been removed. The transport never uses browser cookies, implicit redirect handling or a Referer to forward credentials.

## Time, bytes and failures

`beforeSending` captures the monotonic start of each network hop; `on_stats` captures completion, including real transport failures. Latency is the cumulative measured network milliseconds across hops. A refused initial destination has no measurable network latency and returns null, never a manufactured zero. A measured very fast transfer can legitimately be close to zero. `observedAt` is UTC; the scheduled reference remains separately in the context.

Each following connection receives only the unused network timeout, with millisecond cURL granularity. Exhausting the configured budget is a timeout even when a response arrived at the exact boundary. DNS authorization and waits for unrelated pool responses are excluded from measured network latency/budget. **Since M3.04, each guard lookup has a separate cumulative 1000 ms DNS deadline**, using the shared TLS/DNS adapter instead of `dns_get_record()`. DNS remains excluded from HTTP network latency and the hop budget; sequential guard authorization and pool-round waits mean this is not an end-to-end HTTP wall-clock deadline. See [TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md); the runner must retain a bounded worker lifetime.

`BoundedResponseBuffer` attaches a PHP write filter sharing `ResponseByteBudget` across all response hops. It rejects the first decoded write bucket that would exceed the cap and aborts the transport rather than silently truncating. This applies to actual decompressed cURL output and Laravel fake sink writes alike, without replacing Guzzle's cURL write callbacks. Temporary resources close on success and exception paths. An incomplete response can never satisfy keyword absence. The cap counts response **body** bytes, including bodies of followed redirects; it is not a header-size or throughput guarantee.

`CheckError` distinguishes invalid destination, local name, blocked address, failed resolution, redirect limit/downgrade, cross-origin body refusal, unusable redirect, timeout, connection failure, TLS failure, oversized response, unexpected status and keyword mismatch. Messages are fixed English descriptions; transport exception messages and server-provided bodies are not copied into outcomes or logs. Unknown exceptions propagate as programming/setup failures instead of becoming target outages. The future runner must handle them as infrastructure errors under the existing ledger fence. `statusCode` is nullable and describes a received status on the current hop; aborted transfers may provide no status. No database or incident semantics are inferred here.

## Explicit scope decisions and remaining integration

- The user chose **latency measurement only** for M3.03. The latency-failure threshold mentioned in the product specification remains deferred; no model/form field or implicit threshold is added and the specification is not silently changed.
- TLS transport adapters, DNS strategies and bounded DNS resolution are implemented in [M3.04](TLS-DNS-CHECKERS.md); heartbeat ingress and pure evaluation are implemented in [M3.05](HEARTBEATS.md).
- Application consumer binding, worker middleware/failure hooks, result persistence and partitioning remain M3.06–M3.07. The runner must use the ledger acquisition fence and atomically persist results with completion.
- Scheduler activation remains deferred. This task adds no route, environment variable, migration, dependency or lockfile change and does not apply prior migrations to development.

## Verification

- `HttpCheckStrategyTest`: pure status and exact keyword cases, status precedence, unsupported/inconsistent variants, result invariants and exact/cumulative sink caps.
- `HttpCheckCoordinatorTest`: fake DNS/HTTP/clock; mixed inputs, tenant and batch validation before I/O, methods/bodies/pinning, redirect rewriting and credential removal, cumulative timeout and byte limits, safe error classification, no retries and propagation of programming errors.
- `HttpCheckTransportTest`: a synthetic nonblocking loopback server requires both barrier requests before releasing either response. This proves real concurrent sockets without fragile elapsed-time comparisons. Additional probes cover decompressed gzip overflow, one hanging target alongside a healthy response, and denial before I/O without a loopback policy exception. The server is a bounded, cleaned-up child process; no external network or customer target is contacted.

The full acceptance command is `./scripts/verify.sh`. Actual results and limits are recorded in [TASKS.md](TASKS.md); no remote CI, PHP 8.5 integration, browser test or load benchmark is implied by these local probes.
