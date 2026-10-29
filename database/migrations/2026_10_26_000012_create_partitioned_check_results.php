<?php

declare(strict_types=1);

/** Persist exactly one terminal result per immutable execution slot, partitioned by UTC calendar month. */

use App\Services\Monitoring\Results\ResultPartitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Result identity is anchored to the ledger so another partition cannot admit a second date for the same execution. */
return new class () extends Migration {
    /** Create ownership constraints, queue-attempt fencing and the initially prepared result months. */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->timestampTz('last_result_slot_at')->nullable();
        });
        Schema::table('check_executions', function (Blueprint $table): void {
            $table->uuid('queue_uuid')->nullable();
            $table->integer('queue_attempt')->nullable();
            $table->unique(['team_id', 'id', 'monitor_id', 'slot_at', 'region', 'monitor_revision'], 'check_executions_result_identity');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE check_executions ADD CONSTRAINT check_execution_queue_owner CHECK (
                (queue_uuid IS NULL AND queue_attempt IS NULL) OR (queue_uuid IS NOT NULL AND queue_attempt IS NOT NULL AND queue_attempt > 0)
            );
            CREATE TABLE check_results (
                id bigint GENERATED ALWAYS AS IDENTITY,
                team_id bigint NOT NULL,
                execution_id bigint NOT NULL,
                monitor_id bigint NOT NULL,
                region varchar(8) NOT NULL CHECK (region IN ('eu', 'us')),
                slot_at timestamptz NOT NULL,
                monitor_revision bigint NOT NULL CHECK (monitor_revision > 0),
                kind varchar(32) NOT NULL CHECK (kind IN ('observed', 'missed', 'infrastructure_failed', 'cancelled')),
                status varchar(16) NOT NULL CHECK (status IN ('up', 'down', 'unknown')),
                checked_at timestamptz(6),
                recorded_at timestamptz(6) NOT NULL,
                response_time_ms integer CHECK (response_time_ms >= 0),
                status_code integer CHECK (status_code BETWEEN 100 AND 599),
                error_code varchar(64),
                error_message text,
                tls_expires_at timestamptz,
                PRIMARY KEY (id, slot_at),
                UNIQUE (execution_id, slot_at),
                FOREIGN KEY (team_id, execution_id, monitor_id, slot_at, region, monitor_revision)
                    REFERENCES check_executions (team_id, id, monitor_id, slot_at, region, monitor_revision) ON DELETE CASCADE,
                CHECK (
                    (kind = 'observed' AND checked_at IS NOT NULL AND (
                        (status = 'up' AND error_code IS NULL AND error_message IS NULL)
                        OR (status = 'down' AND error_code IS NOT NULL AND error_message IS NOT NULL)
                    )) OR (kind <> 'observed' AND status = 'unknown' AND checked_at IS NULL
                        AND response_time_ms IS NULL AND status_code IS NULL AND tls_expires_at IS NULL
                        AND error_code IS NOT NULL AND error_message IS NOT NULL)
                )
            ) PARTITION BY RANGE (slot_at);
            CREATE INDEX check_results_monitor_slot ON check_results (team_id, monitor_id, slot_at DESC, region);
            CREATE OR REPLACE FUNCTION reject_check_result_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Recorded check results are immutable' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER check_results_immutable BEFORE UPDATE ON check_results
                FOR EACH ROW EXECUTE FUNCTION reject_check_result_update();
            SQL);
        app(ResultPartitions::class)->prepare();
    }

    /** Remove dependent results first, then their additional ledger identity and monitor watermark. */
    public function down(): void
    {
        Schema::dropIfExists('check_results');
        DB::statement('DROP FUNCTION IF EXISTS reject_check_result_update()');
        DB::statement('ALTER TABLE check_executions DROP CONSTRAINT check_execution_queue_owner');
        Schema::table('check_executions', function (Blueprint $table): void {
            $table->dropUnique('check_executions_result_identity');
            $table->dropColumn(['queue_uuid', 'queue_attempt']);
        });
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('last_result_slot_at');
        });
    }
};
