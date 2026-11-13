<?php

declare(strict_types=1);

/** Store one rolling 24-hour usage counter per team and quota, independent of the rows that consumed it. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The counter belongs to the team, not to a channel or invitation, so deleting and recreating those rows never
 * resets it. Rows disappear with their team.
 */
return new class () extends Migration {
    /** Create the counter table with a composite key and a non-negative usage check. */
    public function up(): void
    {
        Schema::create('team_daily_quotas', function (Blueprint $table): void {
            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('quota', 40);
            $table->timestampTz('window_started_at');
            $table->unsignedInteger('used');
            $table->primary(['team_id', 'quota']);
        });

        DB::statement('ALTER TABLE team_daily_quotas ADD CONSTRAINT team_daily_quotas_used_check CHECK (used >= 0)');
    }

    /** Drop the counters; limits restart from zero if the migration is applied again. */
    public function down(): void
    {
        Schema::dropIfExists('team_daily_quotas');
    }
};
