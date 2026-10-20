<?php

/** Add optional encrypted second-factor storage and case-insensitive email uniqueness. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Upgrade existing identities without enabling 2FA or merging conflicting accounts. */
return new class () extends Migration {
    /**
     * Reject pre-existing email collisions before normalizing stored identities.
     * @throws LogicException When multiple identities differ only by email casing.
     */
    public function up(): void
    {
        if (DB::table('users')->selectRaw('lower(email)')->groupByRaw('lower(email)')->havingRaw('count(*) > 1')->exists()) {
            throw new LogicException('Resolve case-insensitive email collisions before migrating authentication.');
        }
        DB::statement('UPDATE users SET email = lower(email)');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        Schema::table('users', /** @param Blueprint $table Existing identities receiving disabled-by-default security fields. */ function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
        });
    }

    /** Remove optional security data and the additional index, preserving user/team identities. */
    public function down(): void
    {
        Schema::table('users', /** @param Blueprint $table Existing identities losing only M1.02 security fields. */ function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
        DB::statement('DROP INDEX users_email_lower_unique');
    }
};
