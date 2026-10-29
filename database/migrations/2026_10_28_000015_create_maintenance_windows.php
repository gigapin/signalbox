<?php

declare(strict_types=1);

/** Persist planned maintenance windows that suppress incident opening and are excluded from SLA time. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Windows are half-open UTC intervals [starts_at, ends_at); overlapping windows are allowed and merged on read. */
return new class () extends Migration {
    /** Create maintenance windows owned by the monitor's team. */
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id');
            $table->foreign(['team_id', 'monitor_id'])->references(['team_id', 'id'])->on('monitors')->cascadeOnDelete();
            $table->timestampTz('starts_at');
            // Exclusive end of the window.
            $table->timestampTz('ends_at');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['team_id', 'monitor_id', 'starts_at'], 'maintenance_windows_monitor_schedule');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE maintenance_windows
                ADD CONSTRAINT maintenance_windows_interval_check CHECK (ends_at > starts_at),
                ADD CONSTRAINT maintenance_windows_reason_check CHECK (length(trim(reason)) > 0);
            SQL);
    }

    /** Remove maintenance windows; monitors are unaffected. */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
