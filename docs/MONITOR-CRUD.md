# Monitor CRUD, pause and plan limits

M2.04 adds the production monitor mutations on top of the [model](MONITORS.md), the [input contract](MONITOR-INPUT.md) and the [outbound boundary](OUTBOUND-SECURITY.md). List, detail and form pages, search and filters are M2.05; integrated UI coverage, including concurrent sixth-monitor attempts through the UI, is M2.06. Evidence is in [TASKS.md](TASKS.md).

## Routes and permissions

| Route | Method | Effect | Flash status |
|---|---|---|---|
| `teams.monitors.store` | POST `/teams/{team}/monitors` | Create | `monitor-created` |
| `teams.monitors.update` | PUT/PATCH `/teams/{team}/monitors/{monitor}` | Apply an explicit patch | `monitor-updated` |
| `teams.monitors.destroy` | DELETE `/teams/{team}/monitors/{monitor}` | Delete permanently | `monitor-deleted` |
| `teams.monitors.pause` | POST `/teams/{team}/monitors/{monitor}/pause` | Voluntary pause | `monitor-paused` |
| `teams.monitors.resume` | POST `/teams/{team}/monitors/{monitor}/resume` | Remove voluntary pause | `monitor-resumed` |

All routes use `auth`, `verified` and `EstablishTeamContext`; monitor binding runs inside the route team, so another team's monitor is a 404. Responses redirect back. Input validation failures in the Form Requests flash no input. Refusals raised later by the action (quota, plan interval) use Laravel's default handler, which may flash non-private fields such as the name, while the global `dontFlash` list excludes every monitor configuration column and `headers`. JSON requests receive Laravel's 422 field errors.

`MonitorPolicy` grants `viewAny`/`view` to every current member and `create`, `update`, `delete`, `pause` and `resume` to owners, admins and members. Viewers are read-only. Roles are read fresh from the membership on every check. The routes are part of the [authorization matrix](AUTHORIZATION-MATRIX.md).

## Transactions and locks

`App\Actions\Monitoring\ManageMonitors` owns every transition. Each one:

1. enters the explicit tenant context and opens a transaction;
2. locks the Team row (`SELECT … FOR UPDATE`), then the Monitor row, always in that order, shared with future plan changes (M9);
3. re-runs `MonitorPolicy` for the actor, so a role change or revocation after the HTTP check still denies;
4. for create and update, re-validates the **explicit patch** with `MonitorInputValidator` against the freshly locked snapshot, never the early request candidate, so concurrent edits and omitted secrets are preserved;
5. applies `MonitorLimits`, then writes.

Because the quota count happens after the Team lock, two simultaneous creations cannot both see four of five monitors. The Form Requests still validate early to give fast feedback; the action is the authority.

## Plan limits

`config/plans.php` is the single [ADR-010](ARCHITECTURE.md) definition read through `PlanLimits`; missing or non-positive values raise instead of meaning unlimited.

| Plan | Monitors | Minimum interval |
|---|---:|---:|
| Free | 5 | 300 s |
| Pro | 50 | 60 s |
| Business | 200 | 30 s |

Status page and retention values are declared there too, for M7/M8.

`TeamEntitlement::effectivePlan()` resolves the plan until Stripe subscriptions exist (M9): a stored Pro or Business plan stands for a paid entitlement; a Free team receives Pro while `now < trial_ends_at` (exclusive end); otherwise Free. The trial never lowers a higher plan.

`MonitorLimits` applies:

- **Quota on creation**: all monitors of the team count, paused and plan-suspended included, so pausing never frees a slot. Error key `monitor`.
- **Minimum interval** on creation and whenever an update changes the interval. Error key `interval_seconds`. An interval left unchanged after a downgrade is not re-checked on unrelated edits; ADR-010 reconciliation (M9) adjusts it.
- **Quota position** for resume: monitors ordered by `created_at`, then ID; only the first *limit* positions are within quota.

Messages carry the limit values so the UI can show them without copying the matrix.

## Field semantics

- **Create**: monitor starts with status `unknown`, no pause or suspension and `next_check_at` null. Scheduling slots are initialized by the M3.01 dispatcher.
- **Update**: if the type-specific configuration changes (destination, headers, body, expectations, type), the old target's observation summary resets: `last_status` unknown, `consecutive_failures` 0, `last_checked_at` and `ssl_expires_at` null. If the interval or type changes, `next_check_at` becomes null so the slot is recalculated without bursts. Name, timeout, threshold or region edits keep observation and slot.
- **Delete**: permanent, releasing the quota slot immediately. Future results and incidents will reference monitors with cascading foreign keys.
- **Pause**: idempotent; sets `is_paused` and clears `next_check_at`; never touches `is_plan_suspended`.
- **Resume**: idempotent; requires the monitor's quota position and its current interval to fit the effective plan, otherwise errors on `monitor` or `interval_seconds` ask the user to delete, upgrade or change the interval first. Clears only `is_paused` and the slot. A plan suspension is never removed here; explicit plan resumption is M9.05.

No events or broadcasts are published yet (M6).

## Verification

- `PlanLimitsTest`: the matrix, completeness of every plan entry and refusal of missing, zero, negative or non-integer values.
- `MonitorCrudTest` (PostgreSQL): effective plan at the exact trial boundary; creation of all five types by each managing role; viewer and revoked-member denial on direct action calls; foreign IDs; forged ownership fields; sixth Free monitor refused with paused and suspended monitors counted; deletion freeing a slot; Pro trial quota until the trial end; per-plan interval minimums; edits after a downgrade; secret preservation and reset rules; revalidation against newer state; pause/resume idempotence and independence from plan suspension; quota position and cadence on resume; deletion; and a cross-session test where a second PostgreSQL connection times out (`55P03`) waiting for the Team lock held by an uncommitted creation, then a recount refuses the sixth monitor.
- `TeamAuthorizationMatrixTest`: the five routes across roles, membership states, authentication gates and foreign child IDs.

```sh
docker compose run --rm php-test composer test -- --filter='MonitorCrud|PlanLimits|TeamAuthorizationMatrix|MonitorInput' --fail-on-skipped --fail-on-warning
./scripts/verify.sh
```
