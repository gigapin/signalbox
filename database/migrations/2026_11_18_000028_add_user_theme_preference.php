<?php

/** Preserve explicit account appearance while leaving existing accounts on the dark default. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /** Add the nullable preference without rewriting existing users. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('theme_preference', ['dark', 'light'])->nullable();
        });
    }

    /** Remove only the appearance preference. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme_preference');
        });
    }
};
