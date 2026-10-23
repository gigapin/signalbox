# Platform administration

Milestone MA adds platform operators (super admins) and a panel for overriding team plans, so that test teams can work without plan limits and customers can receive a plan without a subscription. It is outside the product specification: the user decided to add it on 2026-10-23, and the decision is recorded in [TASKS.md](TASKS.md). User management (MA.05) and impersonation (MA.06) are planned but not implemented. The architecture decision is ADR-011 in [ARCHITECTURE.md](ARCHITECTURE.md).

## Operators

An operator is a user with `users.is_platform_admin = true`. Operators are separate from team roles: owning or administering a team grants nothing on the platform.

User choice: the flag lives in the database and can be changed only from the command line.
- It is tied to the user ID, not to an email address, which is mutable.
- It can be revoked without a redeploy.
- Every change is audited.
- No HTTP route can promote a user, so a hijacked web session cannot escalate on its own.
- Putting the email in an environment variable was rejected.

```sh
php artisan signalbox:platform-admin someone@example.com           # grant (asks for confirmation)
php artisan signalbox:platform-admin someone@example.com --revoke  # revoke
php artisan signalbox:platform-admin --list                        # list operators and their 2FA state
```

In Docker, prefix the commands with `docker compose exec web`.
- The account must exist and have a verified email. Unknown and unverified accounts get the same message, so the command does not reveal which accounts exist.
- `--force` skips the prompt, for scripts.
- Re-applying the current state changes nothing and writes no event.
- Each change locks the user row and writes a `user.platform_admin.granted|revoked` audit event with a null actor, which marks a command-line action.

## Access rule

`App\Services\Admin\PlatformAccess` defines the rule, and the `accessPlatformAdmin` gate shares it.

**Who passes:**
- an authenticated user with a verified email;
- whose flag, re-read from the database on every check, is true. A revocation therefore applies on the next request of an already open session.

**Second factor:**
- required in production, where a confirmed two-factor authentication is needed;
- optional in every other environment (user choice);
- the rule comes from `app()->isProduction()`, and no variable can switch it off in production.

**Admin panel (`/admin`):**
- middleware `auth`, `verified`, `EnsurePlatformAdmin` and `NoStoreAuthentication`;
- non-operators, whatever their team role, receive **404**, so the panel's existence is not revealed;
- an operator without the production second factor is redirected to `/account/security` with a request to enable it;
- changes also need `password.confirm`.

**Horizon (`/horizon`):** `viewHorizon` delegates to the same gate (user choice). `HORIZON_ADMIN_EMAILS` no longer exists. Remove it from any existing `.env`; it has no effect any more.

## Plan overrides

A team has at most one override, stored in `team_plan_overrides`:

| Kind | Effect | Plan |
|---|---|---|
| `unlimited` | Testing without plan constraints | must be null |
| `comped` | Plan granted without a subscription | `pro` or `business` |

- `expires_at` is optional. It is an exclusive UTC end, like the trial; when null, the override lasts until an operator removes it.
- The panel takes an inclusive "valid through" UTC date and stores the start of the following day.
- A reason (at most 500 characters) is required.
- PostgreSQL constraints enforce that kind and plan are coherent and that the reason is not blank.

### Precedence

`TeamEntitlement::resolve()` returns the effective plan, its source and the source's end, checking these in order:

1. **Active unlimited override.** The plan is `internal` and the source is `unlimited_override`.
2. **Active comped override.** The higher of the granted plan and the stored paid plan applies, so a gift never lowers a paid plan. The source is `comped_override`, or `subscription` when the stored plan is higher.
3. **Stored Pro or Business plan.** The source is `subscription`; until M9 this stands for a Stripe subscription.
4. **Initial trial.** The plan is Pro and the source is `trial`.
5. **Free.**

`effectivePlan()` delegates to `resolve()`, so `MonitorLimits` (creation, interval, resume position, usage) honours overrides without any change of its own. M9 must keep this precedence (M9.04) and include an override's expiry or removal in the reconciliation (M9.05).

### The internal profile

`Plan::Internal` is a profile in `config/plans.php`:

| Limit | Value |
|---|---|
| Monitors | 1000 |
| Minimum interval | 30 seconds, the input validator's floor |
| Status pages | 100 |
| Rollup retention | 730 days |

These limits are high but finite: ADR-010 forbids treating any value as unlimited. The profile is never stored, because `teams_plan_check` still allows only `free|pro|business`, and it is never sold.

### When an override ends

When an override expires or is removed, the team falls back to its normal plan. Monitors beyond the new quota keep their state but block new creations, as with a downgrade today (ADR-010). Suspending them is the job of M9.05.

### Concurrency

`ManagePlanOverrides::grant/clear`:
- takes the **Team row lock**, the same one monitor quota mutations use (ADR-004), so a creation never counts against an override that is changing;
- re-runs the operator gate under the lock;
- validates the combination, a future expiry and the reason;
- commits the override and its audit event together;
- writes nothing when an identical override is applied again or when there is nothing to remove.

## Panel

| Route | Purpose |
|---|---|
| `GET /admin` | Redirects to the team directory |
| `GET /admin/teams` | Every team: search by name, slug or owner email (matched literally), 25 per page ordered by name then ID; stored plan, effective plan and source, monitor count |
| `GET /admin/teams/{team}` | Owner, members, trial, effective plan, usage against the effective limits, override form, last 50 audit events |
| `PUT /admin/teams/{team}/plan-override` | Set or replace the override (`kind`, `plan`, `valid_through`, `reason`) |
| `DELETE /admin/teams/{team}/plan-override` | Remove it (`reason`) |

The form asks for confirmation before submitting. Teams and users carry no tenant scope. The panel's monitor count is the only cross-tenant monitor read: an aggregate query outside TeamScope that returns numbers only, never a monitor's name, target, headers, body or configuration. The shared Inertia prop `auth.user.isPlatformAdmin` is the viewer's own flag; it only shows the "Admin" link, and the panel re-checks access on every request.

## Audit

`platform_audit_events` is append-only: the model refuses updates and deletes. Each event holds:
- the actor (null for command-line actions or a deleted user);
- a dotted action name;
- the subject (team or user, kept after the subject is deleted);
- before/after values of non-secret fields;
- the reason;
- the UTC creation time.

## Tests

| Suite | Coverage |
|---|---|
| tests/Integration/PlatformAdminAccessTest.php | Complete route catalogue with middleware; 404 for every team role on every route with nothing written; guest and unverified redirects; operator access and password confirmation; revocation applied to an open session; second factor in production only, for the panel and Horizon; Horizon denied to team owners; the shared flag |
| tests/Integration/PlatformAdminCommandTest.php | Grant with normalized email and audit event, declined confirmation, idempotence, revoke, identical refusal for unknown and unverified accounts, listing |
| tests/Integration/PlanOverrideTest.php | Precedence and exclusive end; Internal never stored; incoherent rows refused by PostgreSQL; an unlimited test team exceeding the Free quota and cadence, then back to the limits once cleared; a granted plan until an inclusive date, then fallback; no write when an identical override is re-applied; 9 invalid inputs; gate re-checked inside the action; literal search, counts and no monitor configuration in the props; an override waiting on the Team lock (`55P03`) |
| tests/Unit/PlanLimitsTest.php | The internal profile limits |
| tests/frontend/Admin.test.tsx | Directory rows and the admin link, empty versus no-match states, explicit search, payload per choice, confirmation before applying, removal and history, inclusive date shown from the exclusive end |

## Limits and next steps

- User management (MA.05) and impersonation (MA.06) are not implemented. Impersonation will need a separate session marker, a visible banner, a bounded duration, a ban on impersonating other operators, and an audit event at start and stop.
- Operators cannot be managed from the panel; this is deliberate, and only the command line can change the flag.
- Overrides do not suspend excess monitors when they end; that is M9.05.
