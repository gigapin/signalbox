<?php

declare(strict_types=1);

/** Persist notification channels, escalation policies and the per-incident delivery log. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Channel destinations and credentials live in one encrypted column; only owner/admin actions write them.
 * A channel can be active only once verified. Escalation steps reference channels by identity inside JSON,
 * so ManageNotificationChannels refuses to delete a channel that a policy still uses.
 */
return new class () extends Migration {
    /** Create channels, policies and delivery logs owned by one team. */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->text('type');
            $table->text('name');
            // Laravel-encrypted JSON object of the type's destination and secrets.
            $table->text('config_json');
            $table->boolean('is_active')->default(false);
            $table->timestampTz('verified_at')->nullable();
            // HMAC of the pending verification code; never the code itself.
            $table->text('verification_code_hash')->nullable();
            $table->timestampTz('verification_expires_at')->nullable();
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->timestampTz('verification_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['team_id', 'id'], 'notification_channels_owner_unique');
            $table->index(['team_id', 'type'], 'notification_channels_team_type');
        });

        Schema::create('escalation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            // Ordered list of {"after_minutes": int, "channel_ids": list<int>} with strictly increasing delays.
            $table->jsonb('steps_json');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['team_id', 'id'], 'escalation_policies_owner_unique');
        });

        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('incident_id');
            $table->foreign(['team_id', 'incident_id'])->references(['team_id', 'id'])->on('incidents')->cascadeOnDelete();
            // Null once the channel is deleted; the type snapshot keeps the history readable.
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->text('channel_type');
            $table->text('kind');
            // Zero-based escalation step that produced the delivery; null for messages outside a policy step.
            $table->unsignedSmallInteger('step_index')->nullable();
            $table->text('status');
            $table->unsignedSmallInteger('attempts')->default(0);
            // Redacted, bounded provider outcome; never a credential or a full payload.
            $table->text('response')->nullable();
            // Idempotency identity of one delivery, unique per team.
            $table->text('dedupe_key');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['team_id', 'dedupe_key'], 'notification_logs_dedupe_unique');
            $table->index(['team_id', 'incident_id', 'id'], 'notification_logs_incident');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE notification_channels
                ADD CONSTRAINT notification_channels_type_check CHECK (type IN ('email', 'slack', 'telegram', 'webhook', 'sms')),
                ADD CONSTRAINT notification_channels_name_check CHECK (length(trim(name)) > 0 AND length(name) <= 100),
                ADD CONSTRAINT notification_channels_active_check CHECK (NOT is_active OR verified_at IS NOT NULL),
                ADD CONSTRAINT notification_channels_code_check CHECK ((verification_code_hash IS NULL) = (verification_expires_at IS NULL));

            ALTER TABLE escalation_policies
                ADD CONSTRAINT escalation_policies_name_check CHECK (length(trim(name)) > 0 AND length(name) <= 100),
                ADD CONSTRAINT escalation_policies_steps_check CHECK (
                    jsonb_typeof(steps_json) = 'array' AND jsonb_array_length(steps_json) BETWEEN 1 AND 20
                );

            CREATE UNIQUE INDEX escalation_policies_name_unique ON escalation_policies (team_id, lower(name));
            CREATE UNIQUE INDEX escalation_policies_single_default ON escalation_policies (team_id) WHERE is_default;

            ALTER TABLE notification_logs
                ADD CONSTRAINT notification_logs_channel_fk FOREIGN KEY (team_id, channel_id)
                    REFERENCES notification_channels (team_id, id) ON DELETE SET NULL (channel_id),
                ADD CONSTRAINT notification_logs_type_check CHECK (channel_type IN ('email', 'slack', 'telegram', 'webhook', 'sms')),
                ADD CONSTRAINT notification_logs_kind_check CHECK (kind IN ('opened', 'escalated', 'resolved')),
                ADD CONSTRAINT notification_logs_status_check CHECK (status IN ('pending', 'sent', 'failed', 'suppressed')),
                ADD CONSTRAINT notification_logs_sent_check CHECK (status <> 'sent' OR sent_at IS NOT NULL),
                ADD CONSTRAINT notification_logs_response_check CHECK (response IS NULL OR length(response) <= 1000);
            SQL);
    }

    /** Remove delivery logs, policies and channels. */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('escalation_policies');
        Schema::dropIfExists('notification_channels');
    }
};
