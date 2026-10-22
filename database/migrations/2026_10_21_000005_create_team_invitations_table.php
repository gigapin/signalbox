<?php

/** Persist revocable, expiring invitations without retaining bearer tokens. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Keep one invitation lifecycle per normalized team/email pair. */

return new class () extends Migration {
    /** Create invitation state and PostgreSQL invariants without changing existing identities. */
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role');
            $table->char('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['team_id', 'email']);
        });
        DB::statement("ALTER TABLE team_invitations ADD CONSTRAINT invitation_role_check CHECK (role IN ('owner', 'admin', 'member', 'viewer'))");
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT invitation_email_check CHECK (email = lower(trim(email)))');
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT invitation_state_check CHECK (accepted_at IS NULL OR revoked_at IS NULL)');
    }

    /** Remove only invitation state when this incremental migration is reversed. */
    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
