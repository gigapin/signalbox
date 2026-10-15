<?php

/** Store only hashed Sanctum bearer tokens, abilities and expiration metadata. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Create the package-compatible polymorphic token store; token issuance routes belong to M10. */
return new class () extends Migration {
    /** Create indexed token lookup, ownership and nullable per-token expiry timestamps. */
    public function up(): void
    {
        Schema::create(
            'personal_access_tokens',
            /** @param Blueprint $table Schema definition for hashed credentials and their polymorphic owner. */
            function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            },
        );
    }

    /** Remove the token store when explicitly rolling back this migration. */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
