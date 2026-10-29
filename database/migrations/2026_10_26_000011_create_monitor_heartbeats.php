<?php

declare(strict_types=1);

/** Persist heartbeat credentials, arming generations and receipts independently of check results. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Composite foreign keys prevent cross-tenant heartbeat state even outside Eloquent. */
return new class () extends Migration {
    /** Create heartbeat storage and arm existing heartbeat monitors at this migration's database instant. */
    public function up(): void
    {
        Schema::create('monitor_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('monitor_id')->unique();
            $table->foreign(['team_id', 'monitor_id'])->references(['team_id', 'id'])->on('monitors')->cascadeOnDelete();
            $table->unique(['team_id', 'id']);
            $table->string('token_hash', 64)->nullable()->unique();
            $table->bigInteger('generation')->default(1);
            $table->timestampTz('started_at', 6);
            $table->timestampTz('last_received_at', 6)->nullable();
        });

        Schema::create('heartbeat_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('monitor_heartbeat_id');
            $table->foreign(['team_id', 'monitor_heartbeat_id'])->references(['team_id', 'id'])->on('monitor_heartbeats')->cascadeOnDelete();
            $table->bigInteger('generation');
            $table->timestampTz('received_at', 6);
            $table->unique(['team_id', 'monitor_heartbeat_id', 'generation', 'received_at'], 'heartbeat_receipts_observation_unique');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE monitor_heartbeats
                ADD CONSTRAINT heartbeat_generation_positive CHECK (generation > 0),
                ADD CONSTRAINT heartbeat_hash_valid CHECK (token_hash IS NULL OR token_hash ~ '^[a-f0-9]{64}$'),
                ADD CONSTRAINT heartbeat_receipt_after_start CHECK (last_received_at IS NULL OR last_received_at >= started_at);
            ALTER TABLE heartbeat_receipts ADD CONSTRAINT heartbeat_receipts_generation_positive CHECK (generation > 0);
            INSERT INTO monitor_heartbeats (team_id, monitor_id, started_at)
                SELECT team_id, id, CURRENT_TIMESTAMP FROM monitors WHERE type = 'heartbeat';
            SQL);
    }

    /** Remove receipts before their owning state; existing monitor definitions are preserved. */
    public function down(): void
    {
        Schema::dropIfExists('heartbeat_receipts');
        Schema::dropIfExists('monitor_heartbeats');
    }
};
