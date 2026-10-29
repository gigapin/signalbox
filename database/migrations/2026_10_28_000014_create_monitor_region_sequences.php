<?php

declare(strict_types=1);

/** Persist each region's consecutive failure sequence used to confirm and recover incidents. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One row per monitor and region, rewritten under the monitor lock of the terminal result transaction. */
return new class () extends Migration {
    /** Create sequences with tenant ownership and counter coherence enforced by PostgreSQL. */
    public function up(): void
    {
        Schema::create('monitor_region_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id');
            $table->foreign(['team_id', 'monitor_id'])->references(['team_id', 'id'])->on('monitors')->cascadeOnDelete();
            $table->string('region', 8);
            // Execution revision the counters were built from; a different current revision means a reset.
            $table->bigInteger('monitor_revision');
            $table->timestampTz('last_slot_at');
            $table->string('last_status', 16);
            $table->integer('consecutive_failures');
            // First failing slot of the current sequence.
            $table->timestampTz('sequence_started_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['monitor_id', 'region'], 'monitor_region_sequences_identity');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE monitor_region_sequences
                ADD CONSTRAINT monitor_region_sequences_region_check CHECK (region IN ('eu', 'us')),
                ADD CONSTRAINT monitor_region_sequences_revision_check CHECK (monitor_revision > 0),
                ADD CONSTRAINT monitor_region_sequences_status_check CHECK (last_status IN ('up', 'down', 'unknown')),
                ADD CONSTRAINT monitor_region_sequences_count_check CHECK (
                    consecutive_failures >= 0
                    AND ((consecutive_failures = 0) = (sequence_started_at IS NULL))
                    AND ((consecutive_failures = 0) = (last_error_code IS NULL))
                    AND ((consecutive_failures > 0) = (last_status = 'down'))
                    AND (sequence_started_at IS NULL OR sequence_started_at <= last_slot_at)
                );
            SQL);
    }

    /** Remove the sequences; the next results rebuild them from empty counters. */
    public function down(): void
    {
        Schema::dropIfExists('monitor_region_sequences');
    }
};
