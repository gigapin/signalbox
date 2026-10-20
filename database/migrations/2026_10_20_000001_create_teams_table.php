<?php

/** Add team identity and initial trial history without modifying existing users. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Store primary ownership, IANA timezone and UTC entitlement preparation. */
return new class () extends Migration {
    /** Create teams with restricted primary-owner deletion and validated plan identifiers. */
    public function up(): void
    {
        Schema::create('teams', /** @param Blueprint $table Persisted team identity and trial history. */ function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('plan')->default('free');
            $table->string('timezone')->default('UTC');
            $table->timestampTz('trial_started_at');
            $table->timestampTz('trial_ends_at');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_plan_check CHECK (plan IN ('free', 'pro', 'business'))");
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_trial_order_check CHECK (trial_ends_at > trial_started_at)');
    }

    /** Remove team identity after dependent domain migrations have been reversed. */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
