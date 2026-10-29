<?php

declare(strict_types=1);

/** Record historical voluntary pause and plan suspension periods from every write path to monitors. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A trigger opens and closes periods when the flags change, so actions, billing jobs and bulk updates all record history. */
return new class () extends Migration {
    /** Create periods, the recording trigger and periods for monitors already paused or suspended. */
    public function up(): void
    {
        Schema::create('monitor_pause_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id');
            $table->foreign(['team_id', 'monitor_id'])->references(['team_id', 'id'])->on('monitors')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->timestampTz('started_at', 6);
            // Exclusive end; null while the pause or suspension is still active.
            $table->timestampTz('ended_at', 6)->nullable();
            $table->index(['team_id', 'monitor_id', 'started_at'], 'monitor_pause_periods_timeline');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE monitor_pause_periods
                ADD CONSTRAINT monitor_pause_periods_kind_check CHECK (kind IN ('voluntary', 'plan_suspension')),
                ADD CONSTRAINT monitor_pause_periods_interval_check CHECK (ended_at IS NULL OR ended_at >= started_at);
            CREATE UNIQUE INDEX monitor_pause_periods_single_open ON monitor_pause_periods (monitor_id, kind) WHERE ended_at IS NULL;
            CREATE OR REPLACE FUNCTION record_monitor_pause_periods() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                was_paused boolean := TG_OP = 'UPDATE' AND OLD.is_paused;
                was_suspended boolean := TG_OP = 'UPDATE' AND OLD.is_plan_suspended;
            BEGIN
                -- now() is the transaction instant, shared with every other write of the same change.
                IF NEW.is_paused AND NOT was_paused THEN
                    INSERT INTO monitor_pause_periods (team_id, monitor_id, kind, started_at) VALUES (NEW.team_id, NEW.id, 'voluntary', now());
                ELSIF was_paused AND NOT NEW.is_paused THEN
                    UPDATE monitor_pause_periods SET ended_at = now() WHERE monitor_id = NEW.id AND kind = 'voluntary' AND ended_at IS NULL;
                END IF;
                IF NEW.is_plan_suspended AND NOT was_suspended THEN
                    INSERT INTO monitor_pause_periods (team_id, monitor_id, kind, started_at) VALUES (NEW.team_id, NEW.id, 'plan_suspension', now());
                ELSIF was_suspended AND NOT NEW.is_plan_suspended THEN
                    UPDATE monitor_pause_periods SET ended_at = now() WHERE monitor_id = NEW.id AND kind = 'plan_suspension' AND ended_at IS NULL;
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE TRIGGER monitors_record_pause_periods AFTER INSERT OR UPDATE OF is_paused, is_plan_suspended ON monitors
                FOR EACH ROW EXECUTE FUNCTION record_monitor_pause_periods();
            -- The real start of existing pauses is unknown; history begins at this migration.
            INSERT INTO monitor_pause_periods (team_id, monitor_id, kind, started_at)
                SELECT team_id, id, 'voluntary', now() FROM monitors WHERE is_paused;
            INSERT INTO monitor_pause_periods (team_id, monitor_id, kind, started_at)
                SELECT team_id, id, 'plan_suspension', now() FROM monitors WHERE is_plan_suspended;
            SQL);
    }

    /** Remove the trigger before the periods it writes. */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS monitors_record_pause_periods ON monitors');
        DB::statement('DROP FUNCTION IF EXISTS record_monitor_pause_periods()');
        Schema::dropIfExists('monitor_pause_periods');
    }
};
