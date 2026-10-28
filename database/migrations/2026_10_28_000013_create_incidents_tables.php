<?php

declare(strict_types=1);

/** Persist incidents and their append-only timeline with lifecycle coherence enforced by PostgreSQL. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ownership is anchored to the monitor's team, and at most one unresolved incident may exist per monitor. */
return new class () extends Migration {
    /** Create incidents, timeline entries, their constraints and the timeline immutability trigger. */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id');
            $table->foreign(['team_id', 'monitor_id'])->references(['team_id', 'id'])->on('monitors')->cascadeOnDelete();
            $table->string('status');
            $table->string('severity');
            // Stable check error classification of the confirming failure, not free text.
            $table->string('cause', 64);
            // Cause declared by people while handling the incident.
            $table->text('root_cause_note')->nullable();
            // Slot of the first failure in the confirmed sequence.
            $table->timestampTz('started_at');
            $table->timestampTz('confirmed_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            // Seconds from started_at to the latest resolution, including short gaps before a reopening.
            $table->bigInteger('duration_seconds')->nullable();
            $table->integer('reopened_count')->default(0);
            $table->timestampTz('last_reopened_at')->nullable();
            $table->timestampsTz();
            $table->unique(['team_id', 'id'], 'incidents_owner_unique');
            $table->index(['team_id', 'monitor_id', 'started_at'], 'incidents_monitor_timeline');
        });

        Schema::create('incident_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('incident_id');
            $table->foreign(['team_id', 'incident_id'])->references(['team_id', 'id'])->on('incidents')->cascadeOnDelete();
            // Null marks an entry written by the system rather than a person.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Incident status after this entry.
            $table->string('status');
            $table->text('message');
            $table->boolean('is_public')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['team_id', 'incident_id', 'created_at'], 'incident_updates_timeline');
        });

        // Literal values freeze this migration; adding an enum case requires a new migration, enforced by tests.
        DB::unprepared(<<<'SQL'
            ALTER TABLE incidents
                ADD CONSTRAINT incidents_status_check CHECK (status IN ('investigating', 'identified', 'monitoring', 'resolved')),
                ADD CONSTRAINT incidents_severity_check CHECK (severity IN ('minor', 'major', 'critical')),
                ADD CONSTRAINT incidents_cause_check CHECK (cause IN (
                    'invalid_destination', 'local_name', 'blocked_address', 'resolution_failed', 'redirect_limit',
                    'redirect_downgrade', 'cross_origin_body', 'invalid_redirect', 'timeout', 'connection_failed', 'tls_failed',
                    'response_too_large', 'unexpected_status', 'keyword_mismatch', 'tls_expiring', 'dns_missing', 'dns_mismatch',
                    'dns_response_failed', 'dns_malformed', 'heartbeat_missed'
                )),
                ADD CONSTRAINT incidents_note_check CHECK (root_cause_note IS NULL OR length(trim(root_cause_note)) > 0),
                ADD CONSTRAINT incidents_confirmation_check CHECK (confirmed_at >= started_at),
                ADD CONSTRAINT incidents_resolution_check CHECK (
                    ((status = 'resolved') = (resolved_at IS NOT NULL))
                    AND ((resolved_at IS NULL) = (duration_seconds IS NULL))
                    AND (resolved_at IS NULL OR resolved_at >= confirmed_at)
                    AND (duration_seconds IS NULL OR duration_seconds >= 0)
                ),
                ADD CONSTRAINT incidents_acknowledgement_check CHECK (acknowledged_by IS NULL OR acknowledged_at IS NOT NULL),
                ADD CONSTRAINT incidents_reopen_check CHECK (
                    reopened_count >= 0
                    AND ((reopened_count = 0) = (last_reopened_at IS NULL))
                    AND (last_reopened_at IS NULL OR last_reopened_at >= confirmed_at)
                );
            CREATE UNIQUE INDEX incidents_single_open ON incidents (monitor_id) WHERE resolved_at IS NULL;
            ALTER TABLE incident_updates
                ADD CONSTRAINT incident_updates_status_check CHECK (status IN ('investigating', 'identified', 'monitoring', 'resolved')),
                ADD CONSTRAINT incident_updates_message_check CHECK (length(trim(message)) > 0),
                ADD CONSTRAINT incident_updates_publication_check CHECK (is_public = (published_at IS NOT NULL));
            CREATE OR REPLACE FUNCTION guard_incident_update_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Content, status, ownership and creation instant never change.
                IF (to_jsonb(NEW) - 'is_public' - 'published_at' - 'author_id')
                    IS DISTINCT FROM (to_jsonb(OLD) - 'is_public' - 'published_at' - 'author_id') THEN
                    RAISE EXCEPTION 'Incident timeline entries are append-only' USING ERRCODE = '23514';
                END IF;
                -- The author may only be cleared, which the users foreign key does when an account is deleted.
                IF NEW.author_id IS DISTINCT FROM OLD.author_id AND NEW.author_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Incident timeline authors are immutable' USING ERRCODE = '23514';
                END IF;
                -- Visibility changes only by a single publication of a private entry.
                IF (NEW.is_public, NEW.published_at) IS DISTINCT FROM (OLD.is_public, OLD.published_at)
                    AND NOT (OLD.is_public = false AND NEW.is_public = true AND NEW.published_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'Incident timeline entries can only be published once' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER incident_updates_append_only BEFORE UPDATE ON incident_updates
                FOR EACH ROW EXECUTE FUNCTION guard_incident_update_change();
            SQL);
    }

    /** Remove the timeline before incidents, then the trigger function they no longer use. */
    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
        DB::statement('DROP FUNCTION IF EXISTS guard_incident_update_change()');
    }
};
