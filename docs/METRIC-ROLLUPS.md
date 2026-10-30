# Check rollups (M7.01–M7.02)

Hourly and daily UTC rollups keep uptime, check counts and latency after the raw monthly result partitions are dropped. They implement the storage side of [ADR-008 and ADR-009](ARCHITECTURE.md#adr-008--retention-rollup-e-percentili-esatti). The SLA service for arbitrary windows is M7.03; nightly scheduling, retention and partition drops are M7.04.

## Tables

Migration `2026_10_30_000021_create_check_rollups.php` adds three tables. All have a composite foreign key to the monitor's team and cascade with the monitor.

| Table | Key | Content |
| --- | --- | --- |
| `check_rollups_hourly` | `(monitor_id, hour)` | One row per monitored UTC hour, including hours without checks |
| `check_rollups_daily` | `(monitor_id, day)` | One row per closed UTC day; `hours` is below 24 on a monitor's first day |
| `check_rollup_cursors` | `monitor_id` | `hourly_through`: exclusive end of the hours already rolled up |

| Columns | Meaning |
| --- | --- |
| `checks`, `failures`, `unknown_checks` | Regional executions of the bucket's slots: all reserved, observed down, without an observation. They never contribute to availability |
| `up_us`, `down_us`, `unknown_us`, `excluded_us` | Time split in integer microseconds. Their sum is the monitored part of the bucket |
| `uptime_ratio` | Generated: `up / (up + down)`, null without observed time |
| `latency_samples`, `latency_sum_ms`, `latency_counts` | Exact distribution: sample count per whole millisecond, as a JSONB object with millisecond keys |
| `avg_ms` | Generated: sum over samples, null without samples |
| `p50_ms`, `p95_ms` | Sample at position `ceil(p * n)`, null without samples |

Rollups are per monitor, as in the specification schema: frequencies combine both simulated regions, and the regional split does not outlive the raw results.

## Availability rules

`AvailabilityTimeline` is a pure calculation that M7.03 can reuse.

- A slot's verdict takes effect at its latest regional observation. The number of regions that must agree comes from the executions reserved for that slot, not from the current configuration.
- Agreed up or down holds until the next verdict, or at most until `slot + 2 × reserved period`: the following expected slot must also have expired. Stopping at the following slot would leave a gap between any two punctual checks, and an SLA could never be determined.
- Discordant or partial regional coverage is an unknown verdict: it ends the previous one. A slot with no observation changes nothing, so the previous verdict ages out.
- A result arriving after a newer slot's verdict is history: it is ignored and fills no gap.
- Time before the first observation and after expiry is unknown. Time before the monitor's creation belongs to no duration.
- `ExclusionPeriods` supplies merged pauses, plan suspensions and maintenance windows, subtracted once.

Counts and latency belong to the hour of their immutable slot. Durations belong to the hour in which the time passes, so a verdict can span hours, days and months.

## Closed hours and idempotence

An hour is rolled up only once it can no longer change:

- At least one current interval plus 120 seconds has passed since its end. Future reservations select the latest slot at their own instant, and a changed interval restarts cadence after the change.
- No execution of the monitor from that hour or earlier can still be acquired or finish. Expired work awaiting recovery can only become unobserved, and it already counts as `unknown_checks`.

Past exclusions are immutable as well ([MAINTENANCE.md](MAINTENANCE.md)). Together these rules make the calculation deterministic, so writing a bucket again produces the same row.

Daily rows are derived only from hourly rows: frequencies are merged and durations summed. Hourly percentiles and means are never combined.

## Locking

Calculation runs without locks, and at most one UTC day is then written in a short transaction. Foreign keys take `FOR KEY SHARE` on the monitor row while writing, which would delay the check engine's `FOR UPDATE` if it were held during calculation. The write takes the monitor before the cursor, the same order as a cascading monitor deletion. A run that finds the cursor moved discards its calculation, because the other run wrote the same hours.

## Operation

```sh
# Bring every monitor up to date; one failing monitor is logged by identity and does not stop the others.
php artisan metrics:rollups

# Rewrite hours already rolled up from the raw results.
php artisan metrics:rollups --monitor=ID --from=2026-10-02T09:00:00Z --to=2026-10-03T00:00:00Z
```

Exit status 1 reports failed monitors, invalid options or a refused rebuild. A rebuild never goes past the cursor. It is refused when its start, less two maximum intervals, precedes the oldest retained partition: calculating from missing results would wrongly replace good time with unknown.

**The command is not scheduled yet**: the nightly job belongs to M7.04. Apply the migration deliberately to the intended database before running it. A monitor older than the oldest partition starts at that partition.

## Tenancy

Discovery reads only identities. All private work runs inside the owning tenant's context. Raw SQL and bulk writes name their team explicitly, and the source selects only timing and outcome columns, never monitor configuration. Models are read-only and fail closed without a tenant context. No route or API exposes rollups yet.

## Limits

- Buckets are whole UTC hours. After raw results expire, a window with edges inside an hour (for example a team timezone with a fractional offset) cannot be reconstructed: M7.03 must report it as unavailable.
- The frequency map grows with distinct latency values. Its measured cost is under [Percentiles and storage cost](#percentiles-and-storage-cost-m702).
- Deleting a monitor deletes its rollups.

## Percentiles and storage cost (M7.02)

**Decision: percentiles stay exact.** Each bucket keeps the sample count, the sum and the frequency of each whole-millisecond value. The measurements below give no reason to switch to an approximate sketch, which would have to be an explicit recorded decision.

| Rule | Definition |
| --- | --- |
| Latency unit | Whole milliseconds, half rounded up, when the result is recorded |
| Sample | An observation with a measured latency, up or down. Null latency and missing work are not samples; zero is a value |
| Percentile | Value at position `ceil(p * n)` of the sorted samples, calculated with integers. No samples yield null |
| Daily percentile | Calculated on the merged hourly frequencies, never from hourly percentiles |
| Mean | `sum / samples`, never a mean of means |

The definition equals PostgreSQL's `percentile_disc`, which the tests use as an independent reference on the raw results.

### Measurements

Measured on 2026-10-30 on the local PostgreSQL 16 test service, one synthetic day per scenario through the production `RollupStore`, with seeded lognormal latencies (median 120 ms, limited to the 10-second maximum timeout). Each scenario assumes two regions at the fastest cadence of its plan. "All distinct" is the worst possible day: every sample has a different value.

| Scenario | Avg distinct/hour | Distinct/day | Hour row B | Day row B | Day map B | KiB/day | MiB/monitor at retention | GiB/full plan | Day merge ms |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Free 300 s stable | 21.1 | 138 | 543 | 816 | 678 | 13.5 | 1.2 | 0.0 | 2.2 |
| Free 300 s volatile | 23.5 | 302 | 584 | 1384 | 1244 | 15.0 | 1.3 | 0.0 | 2.2 |
| Pro 60 s stable | 73.7 | 176 | 1530 | 1048 | 911 | 36.9 | 13.1 | 0.6 | 2.5 |
| Pro 60 s volatile | 101.3 | 638 | 1278 | 2776 | 2634 | 32.7 | 11.6 | 0.6 | 3.7 |
| Business 30 s stable | 103.3 | 192 | 989 | 1160 | 1021 | 24.3 | 17.3 | 3.4 | 3.0 |
| Business 30 s volatile | 173.7 | 779 | 857 | 3400 | 3259 | 23.4 | 16.7 | 3.3 | 4.9 |
| Business 30 s all distinct | 240.0 | 5760 | 1262 | 21456 | 21310 | 50.5 | 36.0 | 7.0 | 12.5 |

How to read it:

- Only the bytes and distinct counts are repeatable, because the seed is fixed. Merge times are one run on a development machine, not a performance claim.
- Row sizes are logical stored bytes: they exclude indexes and page overhead, so real disk use is higher.
- "MiB/monitor" and "GiB/full plan" are linear **estimates** from one day: retention of 90/365/730 days, and for the full plan 5/50/200 monitors all at the fastest cadence. They are not measurements of a real team.
- Larger maps can take less space than smaller ones. PostgreSQL compresses a value only when the row approaches its roughly 2 KB TOAST threshold, which explains Pro stable rows being larger than Business stable rows.

Distinct values stay bounded by the samples in a bucket: 240 per hour and 5760 per day at the fastest cadence with two regions. They cannot exceed 10001 values in any bucket, given the maximum timeout.

### Reproducing and measuring real data

```sh
# Synthetic scenarios above; prints the table and changes nothing permanently.
docker compose run --rm -e RUN_ROLLUP_STORAGE_BENCHMARK=1 php-test vendor/bin/pest tests/Integration/RollupStorageBenchmarkTest.php --fail-on-skipped

# Actual stored rollups: aggregates only, read-only, scans both tables, never scheduled.
php artisan metrics:rollup-storage
```

The real-data command has only run on synthetic test rows so far, and the estimates should be compared with it once a deployment has real rollups.
