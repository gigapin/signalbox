<?php

declare(strict_types=1);

/** Persist revocable email consent and the durable, tenant-owned public email outbox. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Composite foreign keys prevent recipients, pages and published entries from crossing tenants. */
return new class () extends Migration {
    /** Create encrypted recipient storage and recoverable delivery state. */
    public function up(): void
    {
        Schema::table('incident_updates', function (Blueprint $table): void {
            $table->unique(['team_id', 'id'], 'incident_updates_owner_unique');
        });

        Schema::create('status_page_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('status_page_id');
            $table->text('email');
            // Keyed digest of the lowercase address; ciphertext cannot serve as a uniqueness key.
            $table->string('email_hash', 64);
            $table->uuid('generation');
            // Binds consent to the password in force when the visitor subscribed.
            $table->string('access_hash', 64);
            $table->timestampTz('requested_at');
            $table->timestampTz('verification_expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['team_id', 'status_page_id'])->references(['team_id', 'id'])->on('status_pages')->cascadeOnDelete();
            $table->unique(['status_page_id', 'email_hash']);
            $table->unique(['team_id', 'status_page_id', 'id'], 'status_subscribers_owner_unique');
        });

        Schema::create('status_page_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('status_page_id');
            $table->unsignedBigInteger('subscriber_id');
            $table->unsignedBigInteger('incident_update_id')->nullable();
            $table->uuid('generation');
            $table->uuid('message_uuid')->unique();
            $table->text('dedupe_key');
            $table->string('kind', 20);
            // Encrypted subject/text only; destination remains in the subscriber row.
            $table->text('payload');
            $table->string('status', 20)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claim_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['team_id', 'status_page_id', 'subscriber_id'], 'status_deliveries_subscriber_fk')
                ->references(['team_id', 'status_page_id', 'id'])->on('status_page_subscribers')->cascadeOnDelete();
            $table->foreign(['team_id', 'incident_update_id'], 'status_deliveries_update_fk')
                ->references(['team_id', 'id'])->on('incident_updates')->cascadeOnDelete();
            $table->unique(['subscriber_id', 'dedupe_key']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE status_page_deliveries
                ADD CONSTRAINT status_deliveries_kind CHECK (kind IN ('verification', 'update')),
                ADD CONSTRAINT status_deliveries_update CHECK ((kind = 'update') = (incident_update_id IS NOT NULL)),
                ADD CONSTRAINT status_deliveries_status CHECK (status IN ('pending', 'sent', 'failed', 'suppressed')),
                ADD CONSTRAINT status_deliveries_attempts CHECK (attempts BETWEEN 0 AND 5),
                ADD CONSTRAINT status_deliveries_lease CHECK (claim_until IS NULL OR (claim_token IS NOT NULL AND status = 'pending'));
            CREATE INDEX status_deliveries_due ON status_page_deliveries (available_at, id) WHERE status = 'pending';
            SQL);
    }

    /** Remove the outbox before its recipient table. */
    public function down(): void
    {
        Schema::dropIfExists('status_page_deliveries');
        Schema::dropIfExists('status_page_subscribers');
        Schema::table('incident_updates', function (Blueprint $table): void {
            $table->dropUnique('incident_updates_owner_unique');
        });
    }
};
