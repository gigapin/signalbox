# Web authentication and optional two-factor security

M1.02 implements browser-session registration, login/logout, password recovery, mandatory email verification and optional authenticator-based 2FA. It preserves Laravel 13, Inertia 2, React 19 and the [M1.01 identity foundation](IDENTITY.md). M1.03 invitations/team switching are documented in [TEAMS.md](TEAMS.md), tenant context and role policies M1.04, and API token issuance M10. See [TASKS.md](TASKS.md) for actual verification evidence.

## Dependencies and boundaries

Laravel Fortify 1.41.0 supplies the maintained authentication pipeline, password broker integration, verification controllers, encrypted authenticator storage and QR generation. Its automatic routes are disabled; `routes/auth.php` declares exactly the supported endpoints. Profile editing, ordinary authenticated password changes, passkeys and API endpoints are not enabled. `laravel/passkeys` is a transitive dependency of this Fortify version, not an exposed product feature. Manifest and lock changes are documented in [DEPENDENCIES.md](DEPENDENCIES.md).

Application Form Requests validate registration, reset, password confirmation and setup confirmation; Fortify's login/reset-link requests and the application challenge request retain their existing contracts. Cohesive actions own registration persistence and reset password rules. The existing `CreateTeam` action remains the only production team creator; M1.03 invited registration deliberately creates no team. Application controller adapters orchestrate Fortify and session responses; they do not depend on future tenant authorization.

## Registration and email identity

The English registration form accepts name, email, password/confirmation and team name. Names are trimmed and limited to 255 characters. New/reset passwords require 12–128 characters and confirmation; existing shorter factory/legacy passwords can still authenticate. Password validation makes no external network call.

Emails are trimmed/lowercased by request validation and the User model. The incremental migration normalizes existing emails and adds the PostgreSQL `lower(email)` unique index, preserving the original constraint as well. It stops before changing data if pre-existing case-insensitive collisions exist; an operator must resolve those identities explicitly. There is no automatic account merge. The migration additionally adds nullable `two_factor_secret`, `two_factor_recovery_codes` and `two_factor_confirmed_at`, leaving existing users' 2FA disabled. Reversal removes these security fields and the extra index; it preserves users/teams and does not restore historical email casing.

`RegisterUser` creates User, Team, owner membership, trial history and current-team preference within one transaction. The server creates a lowercase slug from the team name (fallback `team`), a bounded prefix and UUID suffix; equal presentation names remain valid. UTC is the initial team timezone, and the adopted 14-day trial starts at team creation. Role, ownership, plan, timezone and trial input injected into the registration request are not accepted as authoritative.

The `Registered` event is dispatched after commit, allowing Laravel's standard verification notification to observe only a committed aggregate. A database failure rolls back all identity writes and sends no verification notification. Notification transport failure after commit cannot undo the account; the user can sign in and request another verification email. Concurrent duplicate email inserts are constrained by PostgreSQL and translated into a normal email validation error by the HTTP adapter.

## Routes and account lifecycle

| Flow | Method and path | Access |
|---|---|---|
| Registration | GET/POST `/register` | Guest |
| Login | GET/POST `/login` | Guest |
| Logout | POST `/logout` | Authenticated, including unverified users |
| Reset link | GET/POST `/forgot-password` | Guest |
| Password reset | GET `/reset-password/{token}`, POST `/reset-password` | Guest |
| Verification prompt | GET `/email/verify` | Authenticated |
| Verify email | GET `/email/verify/{id}/{hash}` | Authenticated, signed URL, matching identity/email |
| Resend verification | POST `/email/verification-notification` | Authenticated |
| Pending 2FA login | GET/POST `/two-factor-challenge` | Guest with a valid server-owned challenge |
| Account overview | GET `/account` | Authenticated and verified |
| Password confirmation | GET/POST `/user/confirm-password` | Authenticated and verified |
| Security page | GET `/account/security` | Authenticated, verified, recent password |
| Enable/disable 2FA | POST/DELETE `/user/two-factor-authentication` | Authenticated, verified, recent password |
| Confirm setup | POST `/user/confirmed-two-factor-authentication` | Same security boundary |
| QR/manual setup key | GET `/user/two-factor-qr-code`, GET `/user/two-factor-secret-key` | Same security boundary |
| Show/regenerate recovery codes | GET/POST `/user/two-factor-recovery-codes` | Same security boundary |

Registration creates an unverified authenticated session and redirects to `/email/verify`. Verification links expire after 60 minutes. Unverified accounts can use the prompt, resend and logout but cannot enter account/security pages or manage 2FA. Verified users visiting the prompt return to their account.

`/account` projects only the current user's name/email and the membership-derived current-team name, or an explicit unavailable-team state. It never derives authorization from `current_team_id`, exposes another team's data or invents monitoring metrics. Shared Inertia props contain only `appName`, safe status, standard errors and the explicit `auth.user` name/email projection (null for guests).

Login regenerates the session and supports the standard remember-me cookie. A confirmed authenticator leaves the browser unauthenticated until the second factor succeeds. Opening the login page clears any earlier pending challenge. Logout invalidates the session and regenerates the CSRF token. `auth.session` applies throughout the web stack; successful authentication immediately records the password hash so a subsequent password reset revokes even a session that has not yet visited `/account`. Standard remember-me sessions remain valid until their normal credential/cookie lifecycle ends; restoring a remembered authenticated session does not create a fresh password/2FA challenge.

Reset links use the existing broker's 60-minute lifetime, hashed token storage, 60-second notification throttle and single-use deletion. Known, unknown and recently requested emails receive the same public acknowledgement. The reset adapter locks the user row before invoking broker validation, changing the password, rotating the remember token and deleting the reset token. Concurrent reset requests therefore cannot both consume the same token. Reset preserves authenticator configuration and returns to login without authenticating the caller; old pending challenges fail their credential fingerprint check.

## Second-factor state and concurrency

Enabling 2FA generates encrypted pending secret/recovery fields; it does not enable login challenges until a valid six-digit code sets `two_factor_confirmed_at`. The security page supports QR/manual-key enrollment, confirmation, cancellation of pending setup, showing/regenerating eight recovery codes, and disabling confirmed 2FA. Disabling clears all three fields. The recent-password gate lasts 900 seconds; successful confirmation returns to the security page by default; M1.03 also permits a server-selected ownership page after rechecking primary ownership. Previously requested sensitive JSON endpoints and arbitrary intended URLs remain excluded.

Fortify encrypts secret and recovery JSON using the application key. These attributes are hidden and excluded from normal mass assignment. No Eloquent encrypted cast is added on top of Fortify's encryption. Changing the application key without a migration/key-rotation strategy would make existing encrypted authenticators unreadable; normal development setup must not regenerate a configured key.

A pending challenge stores only server-selected user ID, remember preference, start timestamp and a fingerprint of password hash plus encrypted secret in the server-side session. It expires at exactly 300 seconds and is rejected when the password or authenticator configuration changes. Request body/query IDs cannot select the challenged identity. Completion clears the pending data. Exactly one of `code` or `recovery_code` is accepted, preserving leading zeroes in authenticator tokens.

`ProtectTwoFactorState` takes a PostgreSQL user-row lock for challenge verification and every management operation, reloads the actor under that lock, and keeps the lock through credential consumption. Recovery consumption replaces the used code atomically; regeneration invalidates the old set. Other sessions read updated state after waiting for that same lock. Password reset uses the same row-lock boundary. No session preference or tenant role substitutes for this identity boundary.

`ScopedTwoFactorProvider` delegates cryptography to Google2FA while supplying the application clock and one-step (30-second) tolerance. Replay state is keyed by an opaque hash of the authenticator secret, isolating coincident six-digit tokens across identities. Consumed counters are normalized on both sides of cache stores that return numeric strings and retained for 120 seconds. Verification is serialized by the user lock. Development/production instances must share the configured Redis cache for replay/rate state, as the existing Docker setup does; losing that cache can lose recent TOTP replay history. Recovery single-use state remains in PostgreSQL. Tests use controlled time rather than sleeps; the bounded database lock timeout is an actual contention probe.

Sensitive JSON and authentication/account responses use `Cache-Control: no-store, private`. QR/key/recovery values are fetched only on explicit UI actions, live only in component state, and are cleared on confirmation/disable, hide or unmount as applicable. They never enter shared props, Inertia remembered state or third-party QR services. Validation never flashes passwords, TOTP values or recovery codes into the session.

## Rate limits and CSRF

| Operation | Limit and identity |
|---|---|
| Login | 5/minute per lowercase email and IP |
| Pending challenge | 5/minute per server-session user ID and IP |
| Registration, reset-link request, reset submission | 5/minute per route and IP |
| Email verification and resend | 6/minute per authenticated user |
| Recent-password confirmation | 5/minute per authenticated user |
| Authenticator setup confirmation | 5/minute per authenticated user |

All writes retain the framework's CSRF protection. Inertia forms use the existing cookie/session protocol; no CSRF exemption is added. JSON security reads use same-origin credentials and no-store fetches. Expired password confirmation returns HTTP 423 for JSON; the UI revisits the security page so its server-side gate can open the password-confirmation screen.

## Local use and verification

The normal local setup must already have the configured application key, installed dependencies and its own database. Apply migrations only deliberately to the intended local environment; automated verification uses only `php-test` and never runs them against development storage. The existing `MAIL_MAILER=log` default records development notification links locally; configure `APP_URL` to the browser-reachable application URL so signed links use the correct host/port. Do not commit keys, real mail credentials or notification logs.

Tests fake notifications and external HTTP, exercise password-broker and verification state against PostgreSQL, cover negative/temporal cases, verify safe projections and perform two-connection registration/reset/recovery lock probes. DOM tests mount the actual Inertia provider and intercept only network boundaries; they cover submission/loading/errors, keyboard method switching, verification resend, explicit empty states and ephemeral recovery-code fetching/error handling. No real mail provider, third-party authenticator, full browser automation, remote CI or production deployment is claimed.

```sh
./scripts/verify.sh
docker compose run --rm php-test composer test -- --fail-on-skipped
```

## M1.03 invitation continuation

[TEAMS.md](TEAMS.md) specifies the separate invited-registration action and browser routes. A verified matching account must explicitly accept; login, 2FA and verification only continue to the invitation review. An application VerifyEmailResponse adapter preserves that continuation while delegating all non-invitation behavior to Fortify. The account page exposes only a pending-invitation boolean/link; shared auth props remain unchanged. Explicit invitation account switching regenerates session/CSRF and preserves only a freshly resolved capability. No existing ordinary registration or team trial is replaced.
