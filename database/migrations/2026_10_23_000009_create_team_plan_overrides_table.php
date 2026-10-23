<?php

/** Persist at most one operator-granted entitlement override per team. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The stored team plan is untouched; TeamEntitlement combines it with an active override. */
return new class () extends Migration {
    /** Create overrides with kind/plan coherence and a reason enforced by PostgreSQL. */
    public function up(): void
    {
        Schema::create('team_plan_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('plan')->nullable();
            // Exclusive end of the override; null means it lasts until an operator clears it.
            $table->timestampTz('expires_at')->nullable();
            $table->text('reason');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE team_plan_overrides ADD CONSTRAINT team_plan_overrides_kind_check CHECK ((kind = 'unlimited' AND plan IS NULL) OR (kind = 'comped' AND plan IN ('pro', 'business')))");
        DB::statement('ALTER TABLE team_plan_overrides ADD CONSTRAINT team_plan_overrides_reason_check CHECK (length(trim(reason)) > 0)');
    }

    /** Remove overrides; teams fall back to their stored plan and trial. */
    public function down(): void
    {
        Schema::dropIfExists('team_plan_overrides');
    }
};
