<?php

/** Store an optional team preference independently of membership authorization. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Extend existing identities without manufacturing teams or memberships. */
return new class () extends Migration {
    /** Add a nullable reference that is cleared when its selected team is deleted. */
    public function up(): void
    {
        Schema::table('users', /** @param Blueprint $table Existing user table receiving the team preference. */ function (Blueprint $table): void {
            $table->foreignId('current_team_id')->nullable()->constrained('teams')->nullOnDelete();
        });
    }

    /** Remove the reference before reversing team migrations. */
    public function down(): void
    {
        Schema::table('users', /** @param Blueprint $table Existing user table losing the optional preference. */ function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_team_id');
        });
    }
};
