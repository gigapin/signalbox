<?php

/** Add unique memberships with database-enforced role vocabulary. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Associate users with teams without applying a tenant scope to identity resolution. */
return new class () extends Migration {
    /** Create indexed membership pairs and remove them when either parent is deleted. */
    public function up(): void
    {
        Schema::create('team_user', /** @param Blueprint $table Membership identity, role and audit timestamps. */ function (Blueprint $table): void {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestampsTz();
            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE team_user ADD CONSTRAINT team_user_role_check CHECK (role IN ('owner', 'admin', 'member', 'viewer'))");
    }

    /** Remove memberships before dropping either parent table. */
    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
