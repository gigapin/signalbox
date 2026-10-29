# Outbound security and secret redaction

M2.03 implements the shared SSRF boundary required by ADR-002 in [ARCHITECTURE.md](ARCHITECTURE.md). Checkers (M3) and webhooks (M5) must use it for every outbound connection; form validation in [MONITOR-INPUT.md](MONITOR-INPUT.md) applies only its network-free part. No checker, transport loop, CRUD route or UI is introduced here. Verification evidence is in [TASKS.md](TASKS.md).

## Components

| Component | Responsibility |
|---|---|
| `OutboundAddressPolicy` | Decides whether one literal IP may be contacted, comparing packed bytes against CIDR ranges |
| `HostResolver` / `SystemHostResolver` | Replaceable A/AAAA lookup; bound in `AppServiceProvider`, faked in tests |
| `OutboundDestinationGuard` | Authorizes URLs, TLS endpoints and custom DNS resolvers at access time and returns `AuthorizedDestination` |
| `GuardedHttpOptions` | Converts an authorized URL into Guzzle options pinned to its validated addresses |
| `RedirectPolicy` / `RedirectStep` | Re-authorizes each redirect hop, limits hops, refuses downgrades and decides credential forwarding |
| `SecretRedactor` | Redacts URLs, headers, bodies, free text and structured context |
| `SecretRedactionProcessor` / `RedactSecrets` | Monolog processor attached through `tap` to every concrete log channel |
| `MonitorConfigurationProjection` | The only configuration shape allowed in responses, lists, events and logs |

Refusals raise `OutboundDestinationRejected` carrying an `OutboundRejection` reason (`invalid_destination`, `local_name`, `blocked_address`, `resolution_failed`, `redirect_limit`, `redirect_downgrade`). Messages derive only from the reason and never contain hosts, addresses or URLs, so M3 can persist them as check failures.

## Address policy

IPv4 blocked ranges: 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8, 169.254.0.0/16, 172.16.0.0/12, 192.0.0.0/24, 192.0.2.0/24, 192.88.99.0/24, 192.168.0.0/16, 198.18.0.0/15, 198.51.100.0/24, 203.0.113.0/24, 224.0.0.0/4 and 240.0.0.0/4 (broadcast included).

IPv6 is allowed only inside global unicast 2000::/3, minus 2001::/23 (IETF assignments, Teredo included), 2001:db8::/32 and 3fff::/20 (documentation). Consequently loopback, unspecified, unique-local, link-local, site-local, multicast, discard, deprecated IPv4-compatible and NAT64 (64:ff9b::/96) addresses are refused. IPv4-mapped addresses (::ffff:0:0/96) are evaluated exactly as their embedded IPv4 address; 6to4 (2002::/16) also requires its embedded IPv4 address to be allowed.

`inet_pton()` parsing refuses hostnames, CIDR text and legacy numeric spellings such as `2130706433`, `0177.0.0.1` or `127.1`, so a later client cannot interpret an address differently from the policy.

`OUTBOUND_ALLOWED_CIDRS` (config `outbound-security.allowed_cidrs`) is a comma-separated exception list, empty by default and intended only for deliberate local or demo targets. Exceptions take precedence over the blocklist only inside their range; a malformed entry fails policy construction instead of being ignored. Production should keep it empty.

## Access-time authorization and DNS rebinding

`authorizeUrl()` normalizes the URL with the same `MonitorInputSyntax::url()` used by form validation (HTTP/HTTPS only, no userinfo or fragment, canonical host), applies the scheme default port, then:

1. refuses `localhost` and `*.localhost`;
2. checks a literal IP directly, without DNS;
3. otherwise resolves A and AAAA records and refuses the destination if the answer is empty or **any** address is blocked or malformed.

`authorizeEndpoint()` applies the same steps to a TLS host and port; `authorizeResolver()` accepts only a literal resolver address and uses port 53. The returned `AuthorizedDestination` carries the normalized URL and every validated address; its debug representation hides the URL.

`GuardedHttpOptions::for()` returns `allow_redirects => false`, `proxy => ''` and cURL options restricting protocols to HTTP/HTTPS, disabling environment proxies (`CURLOPT_PROXY ''`, `CURLOPT_NOPROXY '*'`) and setting `CURLOPT_RESOLVE` to `host:port:addresses`. The connection therefore uses only the addresses validated a moment earlier, closing the rebinding window between check and connect. Callers may add timeouts but must not override these keys. The [M3.04 TLS adapter](TLS-DNS-CHECKERS.md) likewise connects to a pinned address while sending the original hostname for SNI and certificate verification. Its asynchronous address answers pass through `authorizeResolvedEndpoint()` and the same all-addresses policy.

`SystemHostResolver` queries absolute names without search domains or hosts-file aliases. Since M3.04 it uses the shared cancellable DNS adapter, replacing `dns_get_record()`. `monitor-checks.resolution_timeout_ms` defaults to 1000 ms for A/AAAA and aliases together, with no application cache, alternate resolver fallback or automatic retry. Timeout becomes a safe check failure; other DNS failures authorize no addresses. Trusted system nameservers may be internal infrastructure, while user-selected resolvers still require address authorization. See [TLS-DNS-CHECKERS.md](TLS-DNS-CHECKERS.md).

## Redirects

Redirects are never followed by the HTTP client. For each `Location`, `RedirectPolicy::next()`:

- refuses the hop once `outbound-security.max_redirects` (5) have been followed;
- resolves absolute, scheme-relative, absolute-path, query-only and relative references, discarding fragments;
- re-runs `authorizeUrl()` on the result, producing a new pin;
- refuses HTTPS → HTTP downgrades;
- marks the hop cross-origin when scheme, host or port change. `RedirectStep::headers()` then forwards **no** configured monitor header, so Authorization, Cookie and custom credentials never reach another origin.

Method and body rewriting (301/302/303 versus 307/308) is a transport concern for M3.03.

## Save-time checks

The validator stays network-free. For HTTP/keyword URLs, TLS hosts and DNS resolvers it calls `OutboundDestinationGuard::assertLocallyAllowed()`, rejecting local names and blocked literal addresses (honouring configured exceptions) with a generic field error that never echoes the value. Hostnames are not resolved when saving: their answers can change, so only the access-time guard is authoritative.

## Secrets and redaction

- **URLs**: scheme, host, port and path are kept; every query value, including bare values, becomes `[REDACTED]`; userinfo and fragments are removed.
- **Headers**: names are kept; values are redacted except `accept`, `accept-language`, `content-type` and `user-agent`.
- **Bodies**: only presence and size in bytes.
- **Free text**: `message()` removes known secret strings (longest first) and redacts every embedded URL. M3 `failed()` handlers and transport error classification must pass messages through it with the monitor's header values and other credentials as known secrets.
- **Log context**: keys matching password, secret, token, authorization, cookie, API key, signature, credential, header or body are replaced entirely; keys ending in `url`, `uri` or `location` have URL redaction; all other strings have embedded URLs redacted, recursively. Matching is deliberately broad, so harmless keys such as `token_count` are redacted too. Exception objects are not rewritten: log transport failures with an already redacted message.
- **Stack traces**: URL, headers, body and patch values carry `#[\SensitiveParameter]` in the DTOs and security services.
- **Output**: `MonitorConfigurationProjection` exposes a redacted URL, sorted header names and body size for HTTP/keyword monitors and the non-secret settings of other types. Edit screens (M2.05) must request replacements explicitly instead of repopulating private values.

Every concrete writing channel in `config/logging.php` taps `RedactSecrets`. A new channel must add the same tap.

## Verification

- `OutboundAddressPolicyTest`: every blocked family and boundary, mapped/6to4/NAT64/Teredo forms, legacy spellings, exceptions and malformed configuration.
- `OutboundDestinationGuardTest`: fake DNS with mixed, empty, malformed and rebinding answers; literals, IDNA, userinfo, schemes, TLS/resolver endpoints, safe messages, pinned options and redirect rules.
- `SecretRedactorTest`: URL/header/body/message/context redaction, Monolog processor, projection of all five types and stack trace masking.
- `GuardedHttpPinningTest`: a real loopback PHP server reached through a hostname known only to the fake resolver, with an `HTTP_PROXY` set, proving pinning and proxy bypass; without an exception the same destination is refused before any connection.
- `MonitorInputValidationTest` / `MonitorInputRequestTest`: blocked literals, local names and private resolvers rejected at save time without echo or persistence.

```sh
docker compose run --rm php-test composer test -- --filter='Outbound|SecretRedactor|GuardedHttp|MonitorInput' --fail-on-skipped --fail-on-warning
./scripts/verify.sh
```


## HTTP transport integration (M3.03)

[HTTP-CHECKERS.md](HTTP-CHECKERS.md) applies the guard and redirect policy to every initial/followed HTTP-family connection. `GuardedHttpOptions` now also sets `CURLOPT_FRESH_CONNECT` and `CURLOPT_FORBID_REUSE`: a prior connection cannot bypass the current hop's validated address pin. The shared transport preserves TLS verification, prohibits implicit cookies, implements method/body rewriting and refuses cross-origin nonempty body forwarding. Decoded response writes and network time are cumulatively bounded; M3.04 subsequently bounds synchronous guard resolution with a separate cumulative DNS deadline. The historical M2.03 transport exclusions above describe that milestone's original scope.


## Heartbeat credential boundary (M3.05)

[HEARTBEATS.md](HEARTBEATS.md) adds a push-only ingress with no outbound destination. Its token exists only in Authorization and a one-time management JSON response; persisted state contains only SHA-256 and ordinary Inertia projections contain only a configured flag and timing metadata. Existing credential/token/Authorization redaction covers these fields, and global flash exclusions include token/digest names. The React panel uses ephemeral state and explicit clipboard access, never history or browser storage. Neither endpoint nor curl URL contains a secret.
