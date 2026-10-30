<?php

declare(strict_types=1);

/** Store rebuildable hourly and daily UTC rollups that outlive the raw monthly result partitions. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Durations are integer microseconds; check counts never contribute to the availability columns. */
return new class () extends Migration {
    /** Create both rollup levels, derived ratio/mean columns and the per-monitor progress cursor. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE check_rollups_hourly (
                team_id bigint NOT NULL,
                monitor_id bigint NOT NULL,
                hour timestamptz NOT NULL CHECK (EXTRACT(EPOCH FROM hour)::bigint % 3600 = 0),
                checks integer NOT NULL CHECK (checks >= 0),
                failures integer NOT NULL CHECK (failures >= 0),
                unknown_checks integer NOT NULL CHECK (unknown_checks >= 0),
                up_us bigint NOT NULL CHECK (up_us >= 0),
                down_us bigint NOT NULL CHECK (down_us >= 0),
                unknown_us bigint NOT NULL CHECK (unknown_us >= 0),
                excluded_us bigint NOT NULL CHECK (excluded_us >= 0),
                uptime_ratio double precision GENERATED ALWAYS AS (
                    CASE WHEN up_us + down_us > 0 THEN up_us::double precision / (up_us + down_us) END
                ) STORED,
                latency_samples integer NOT NULL CHECK (latency_samples >= 0),
                latency_sum_ms bigint NOT NULL CHECK (latency_sum_ms >= 0),
                latency_counts jsonb NOT NULL,
                avg_ms double precision GENERATED ALWAYS AS (
                    CASE WHEN latency_samples > 0 THEN latency_sum_ms::double precision / latency_samples END
                ) STORED,
                p50_ms integer,
                p95_ms integer,
                PRIMARY KEY (monitor_id, hour),
                FOREIGN KEY (team_id, monitor_id) REFERENCES monitors (team_id, id) ON DELETE CASCADE,
                CHECK (failures + unknown_checks <= checks),
                CHECK (up_us + down_us + unknown_us + excluded_us <= 3600000000),
                CHECK ((latency_samples = 0) = (p50_ms IS NULL)),
                CHECK ((latency_samples = 0) = (p95_ms IS NULL))
            );
            CREATE TABLE check_rollups_daily (
                team_id bigint NOT NULL,
                monitor_id bigint NOT NULL,
                day date NOT NULL,
                hours smallint NOT NULL CHECK (hours BETWEEN 1 AND 24),
                checks integer NOT NULL CHECK (checks >= 0),
                failures integer NOT NULL CHECK (failures >= 0),
                unknown_checks integer NOT NULL CHECK (unknown_checks >= 0),
                up_us bigint NOT NULL CHECK (up_us >= 0),
                down_us bigint NOT NULL CHECK (down_us >= 0),
                unknown_us bigint NOT NULL CHECK (unknown_us >= 0),
                excluded_us bigint NOT NULL CHECK (excluded_us >= 0),
                uptime_ratio double precision GENERATED ALWAYS AS (
                    CASE WHEN up_us + down_us > 0 THEN up_us::double precision / (up_us + down_us) END
                ) STORED,
                latency_samples integer NOT NULL CHECK (latency_samples >= 0),
                latency_sum_ms bigint NOT NULL CHECK (latency_sum_ms >= 0),
                latency_counts jsonb NOT NULL,
                avg_ms double precision GENERATED ALWAYS AS (
                    CASE WHEN latency_samples > 0 THEN latency_sum_ms::double precision / latency_samples END
                ) STORED,
                p50_ms integer,
                p95_ms integer,
                PRIMARY KEY (monitor_id, day),
                FOREIGN KEY (team_id, monitor_id) REFERENCES monitors (team_id, id) ON DELETE CASCADE,
                CHECK (failures + unknown_checks <= checks),
                CHECK (up_us + down_us + unknown_us + excluded_us <= 86400000000),
                CHECK ((latency_samples = 0) = (p50_ms IS NULL)),
                CHECK ((latency_samples = 0) = (p95_ms IS NULL))
            );
            CREATE TABLE check_rollup_cursors (
                monitor_id bigint PRIMARY KEY,
                team_id bigint NOT NULL,
                hourly_through timestamptz CHECK (EXTRACT(EPOCH FROM hourly_through)::bigint % 3600 = 0),
                FOREIGN KEY (team_id, monitor_id) REFERENCES monitors (team_id, id) ON DELETE CASCADE
            );
            SQL);
    }

    /** Remove rollups and progress; raw results are untouched and can rebuild them while still retained. */
    public function down(): void
    {
        Schema::dropIfExists('check_rollup_cursors');
        Schema::dropIfExists('check_rollups_daily');
        Schema::dropIfExists('check_rollups_hourly');
    }
};
