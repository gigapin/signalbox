<?php

/** Add the platform operator flag, granted and revoked only through the signalbox:platform-admin command. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Existing identities stay ordinary users; no account becomes an operator by migrating. */
return new class () extends Migration {
    /** Add a non-null flag defaulting to false. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
        });
    }

    /** Remove only the operator flag. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
