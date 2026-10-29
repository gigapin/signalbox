<?php

/** Persist an append-only trail of platform operator actions. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Events outlive their actor and subject so deleting either never erases the history. */
return new class () extends Migration {
    /** Create the event table; there is no updated_at because events are never edited. */
    public function up(): void
    {
        Schema::create('platform_audit_events', function (Blueprint $table): void {
            $table->id();
            // Null for actions run from the command line, which have no authenticated web actor.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('changes');
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');
            $table->index(['subject_type', 'subject_id', 'id']);
        });
    }

    /** Remove the audit trail when this incremental migration is reversed. */
    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');
    }
};
