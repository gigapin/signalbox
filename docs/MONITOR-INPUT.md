# Monitor input contract

M2.02 adds network-free validation above the [monitor persistence foundation](MONITORS.md). `MonitorInputValidator` is reusable outside HTTP; StoreMonitorRequest and UpdateMonitorRequest adapt authorization and validation errors without persisting anything. No production monitor route, controller, policy, plan limit or checker is introduced. See [TASKS.md](TASKS.md) for verification evidence.

## Candidate and explicit patch

`validate($submitted, $current)` returns a `ValidatedMonitorInput` containing a complete `MonitorInput` and a `MonitorInputPatch` of normalized, explicitly submitted fields. Creation passes no current snapshot. The caller supplies an authorized snapshot for updates; the HTTP adapter obtains it only after authorization. These objects contain private configuration and must never be serialized into responses, logs, events or queues.

For the same type, omitted fields retain current values, including decrypted URL, headers and body. Explicit null clears only nullable fields; an empty body stays an empty string, while null stays null. Header maps and region/DNS lists replace their entire previous value. Changing type preserves omitted shared fields, discards the previous type-specific configuration and requires the destination type's complete required configuration, with its normal defaults. Unknown, reserved and irrelevant keys are rejected even when null. Only `_token` and `_method` are discarded. Empty update patches are rejected.

`FormRequest::validated()` exposes the normalized explicit patch; `monitorInput()` exposes the complete result. Future mutation actions in M2.04 must lock and reload the monitor, reconstruct a fresh authorized snapshot and revalidate the explicit patch in the transaction. They must not persist the early candidate blindly or add omitted decrypted fields to the patch. This avoids overwriting concurrent edits and keeps the original request free of existing secrets. Neither validation nor the DTO performs a database write.

## Bounds and normalization

The versioned [monitor-input configuration](../config/monitor-input.php) defines operational bounds independently of plan entitlements. M2.04 must additionally enforce plan quotas and minimum cadence.

| Input | Contract |
|---|---|
| Shared | Trimmed name, 1–255 characters; interval 30–86400 seconds; timeout 100–10000 milliseconds; confirmation threshold 1–10, default 3 |
| Regions | Nonempty distinct selection from eu/us, canonical eu then us; default eu; simulated regions |
| HTTP URL | Absolute HTTP(S), at most 2048 bytes, normalized scheme and IDNA/lowercase host; path/query preserved; no userinfo, fragment, backslash, whitespace or control characters; explicit port 1–65535 |
| HTTP settings | GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS; status 100–599; redirects boolean; defaults GET, 200 and false |
| HTTP body | Nullable exact text, at most 65536 bytes; GET/HEAD accept only null or empty body |
| Headers | Complete map, at most 20 names; name at most 128 bytes, value at most 4096, combined names/values at most 16384 bytes |
| Keyword | Exact nonempty text up to 4096 bytes, including meaningful whitespace; mode present/absent, default present |
| TLS | DNS hostname or literal IP; explicit port 1–65535; nullable warning window 0–3650 days, default null |
| DNS | Normalized IDNA query name, allowing underscore labels; A/AAAA/CNAME/MX/TXT; distinct normalized expectation list of 1–32 values, at most 4096 bytes each; nullable resolver, literal IP only |
| Heartbeat | Explicit grace 0–86400 seconds |

Integer inputs accept JSON integers and decimal HTML strings within bounds, rejecting booleans, floats and exponent notation. Hostnames have labels of at most 63 ASCII bytes and a total of 253; a final root dot is normalized away. IP literals are canonicalized locally. URL IPv6 literals retain brackets; ambiguous legacy numeric URL hosts are rejected.

Header names follow HTTP token syntax and normalize to lowercase. Case-insensitive duplicates are errors. Values retain their bytes; horizontal tab is allowed, other ASCII controls and DEL are rejected. Host, Content-Length, Connection, Transfer-Encoding, TE, Trailer, Upgrade and every Proxy-* name are transport-reserved. Authorization and Cookie remain accepted private configuration. Empty header values are preserved; an empty map clears headers.

A/AAAA values must match their address family. CNAME accepts exactly one DNS hostname. MX uses `priority hostname`, priority 0–65535, with `0 .` accepted only as the sole null-MX expectation. TXT values preserve exact bytes, including whitespace. Empty values and duplicates after normalization are rejected. A null resolver delegates to future protected infrastructure; no resolver is contacted during validation.

## HTTP authorization and privacy

Both Form Requests require a verified application user and an established tenant context. Store checks Gate `create` for Monitor; Update additionally requires an existing bound Monitor belonging to the current context and checks Gate `update`. With no production MonitorPolicy yet, both deny by default. Test-only gates exercise the adapters without granting production access. Existing tenant binding hides foreign resources before configuration is decrypted. Authorization precedes validation and snapshot construction.

Global TrimStrings and ConvertEmptyStringsToNull skip only POST/PUT/PATCH requests whose path is `teams/{numeric-team}/monitors` or `teams/{numeric-team}/monitors/{numeric-monitor}`. This check runs before routing and includes zero-padded numeric identifiers; it grants no authorization. The dedicated validator trims metadata while preserving body/header/keyword/TXT bytes. Other application paths retain their existing transformations. Future route prefixes or identifier formats must explicitly extend this contract before being introduced.

JSON failures use Laravel's 422 field-error response without submitted or decrypted values in messages. Web failures redirect with errors and flash no original input. Existing decrypted fields are never merged into HTTP input, and global `dontFlash` also excludes known private monitor configuration for future service validation exceptions. No validation result is returned by the test routes. Future edit screens must provide safe projections explicitly rather than automatically repopulating private values.

Syntax validation does not authorize a network destination. Since M2.03 the validator also applies the network-free part of the [outbound policy](OUTBOUND-SECURITY.md): HTTP/keyword URL hosts, TLS hosts and DNS resolvers that are local names or blocked literal addresses fail with a generic field error. Hostnames are not resolved at save time; the access-time guard repeats the complete policy, pins resolved addresses and validates redirects before checker or webhook I/O. M2.04 owns CRUD/policies, state transitions and concurrent plan limits.

## Verification

MonitorInputSyntaxTest covers local URL/IDNA/IP parsing, headers, DNS families and byte/count boundaries. MonitorInputValidationTest covers all five types, effective merged validation, primitive bounds, exact text, clear/replace semantics, type changes and explicit-patch revalidation against a changed snapshot. MonitorInputRequestTest runs actual adapters/middleware and tenant binding on isolated PostgreSQL, including authorization before decryption, foreign resources, exact bytes, partial PUT/PATCH, safe JSON errors and empty web flash. All HTTP routes and granting gates in that suite are test-only; tests assert no monitor persistence.

```sh
docker compose run --rm php-test composer test -- --filter=MonitorInput --fail-on-skipped --fail-on-warning
./scripts/verify.sh
```
