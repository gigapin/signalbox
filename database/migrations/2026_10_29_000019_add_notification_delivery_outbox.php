<?php

declare(strict_types=1);

/** Extend delivery logs into an encrypted, leased outbox without altering existing history. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Legacy rows remain readable; only fully prepared rows can be published or acquired by the delivery engine. */
return new class () extends Migration {
    /** Add message identity, encrypted recipient content, cycle, schedule and fenced attempt ownership. */
    public function up(): void
    {
        Schema::create('incident_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('incident_id');
            $table->foreign(['team_id', 'incident_id'])->references(['team_id', 'id'])->on('incidents')->cascadeOnDelete();
            $table->unsignedInteger('cycle');
            $table->text('kind');
            $table->timestampTz('created_at');
            $table->unique(['team_id', 'incident_id', 'cycle', 'kind'], 'incident_notification_events_unique');
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->unsignedInteger('cycle')->nullable();
            $table->uuid('message_uuid')->nullable();
            $table->text('message_payload')->nullable();
            $table->char('destination_hash', 64)->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claim_until')->nullable();
            $table->unique(['team_id', 'message_uuid'], 'notification_logs_message_unique');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE incident_notification_events
                ADD CONSTRAINT incident_notification_events_cycle_check CHECK (cycle >= 0),
                ADD CONSTRAINT incident_notification_events_kind_check CHECK (kind IN ('opened', 'resolved'));

            ALTER TABLE notification_logs
                ADD CONSTRAINT notification_logs_outbox_check CHECK (
                    message_uuid IS NULL OR (
                        cycle IS NOT NULL AND cycle >= 0 AND message_payload IS NOT NULL
                        AND destination_hash IS NOT NULL AND available_at IS NOT NULL
                    )
                ),
                ADD CONSTRAINT notification_logs_claim_check CHECK (
                    claim_until IS NULL OR (claim_token IS NOT NULL AND status = 'pending')
                ),
                ADD CONSTRAINT notification_logs_attempts_check CHECK (attempts >= 0);

            CREATE INDEX notification_logs_ready ON notification_logs (available_at, id)
                WHERE status = 'pending' AND message_uuid IS NOT NULL;
            SQL);
    }

    /** Remove only runtime outbox metadata, preserving the original delivery history. */
    public function down(): void
    {
        Schema::dropIfExists('incident_notification_events');
        DB::unprepared(<<<'SQL'
            DROP INDEX notification_logs_ready;
            ALTER TABLE notification_logs
                DROP CONSTRAINT notification_logs_outbox_check,
                DROP CONSTRAINT notification_logs_claim_check,
                DROP CONSTRAINT notification_logs_attempts_check;
            SQL);

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropUnique('notification_logs_message_unique');
            $table->dropColumn(['cycle', 'message_uuid', 'message_payload', 'destination_hash', 'available_at', 'claim_token', 'claim_until']);
        });
    }
};
