<?php

declare(strict_types=1);

/** Persist the regional execution outbox atomically with monitor cadence advancement. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Preserve delivery and fencing state independently of Redis acknowledgement. */
return new class () extends Migration {
    /** Create execution identities, publication leases and bounded acquisition state. */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->unique(['team_id', 'id'], 'monitors_execution_owner_unique');
            $table->bigInteger('execution_revision')->default(1);
        });

        Schema::create('check_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id');
            $table->foreign(['team_id', 'monitor_id'])->references(['team_id', 'id'])->on('monitors')->cascadeOnDelete();
            $table->string('region');
            $table->timestampTz('slot_at');
            $table->timestampTz('expires_at');
            $table->bigInteger('monitor_revision');
            $table->string('state')->default('pending');
            $table->uuid('publication_token')->nullable();
            $table->timestampTz('publication_lease_until')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('available_at');
            $table->uuid('acquisition_token')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['team_id', 'monitor_id', 'slot_at', 'region'], 'check_executions_identity_unique');
            $table->index(['team_id', 'id']);
            $table->index(['state', 'available_at', 'id']);
            $table->index(['state', 'expires_at']);
            $table->index(['state', 'deadline_at']);
        });

        DB::statement('ALTER TABLE monitors ADD CONSTRAINT monitors_execution_revision_check CHECK (execution_revision > 0)');

        DB::unprepared(<<<'SQL'
            ALTER TABLE check_executions
                ADD CONSTRAINT check_executions_revision_check CHECK (monitor_revision > 0),
                ADD CONSTRAINT check_executions_region_check CHECK (region IN ('eu', 'us')),
                ADD CONSTRAINT check_executions_state_check CHECK (state IN ('pending', 'running', 'completed', 'missed', 'cancelled', 'infrastructure_failed')),
                ADD CONSTRAINT check_executions_window_check CHECK (expires_at > slot_at),
                ADD CONSTRAINT check_executions_publication_check CHECK ((publication_token IS NULL) = (publication_lease_until IS NULL)),
                ADD CONSTRAINT check_executions_acquisition_check CHECK (
                    (state = 'running' AND acquisition_token IS NOT NULL AND started_at IS NOT NULL AND deadline_at IS NOT NULL AND deadline_at > started_at AND finished_at IS NULL)
                    OR (state = 'pending' AND acquisition_token IS NULL AND started_at IS NULL AND deadline_at IS NULL AND finished_at IS NULL)
                    OR (state IN ('completed', 'missed', 'cancelled', 'infrastructure_failed') AND finished_at IS NOT NULL)
                );
            SQL);
    }

    /** Remove the execution outbox, ownership index and monitor generation when reversing this migration. */
    public function down(): void
    {
        Schema::dropIfExists('check_executions');
        DB::statement('ALTER TABLE monitors DROP CONSTRAINT monitors_execution_revision_check');
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('execution_revision');
            $table->dropUnique('monitors_execution_owner_unique');
        });
    }
};
