<?php

declare(strict_types=1);

/** Expand opaque digest storage and retain already-issued non-expiring unsubscribe capabilities. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Existing values are unchanged; the separately invoked operational migration authenticates their source. */
return new class () extends Migration {
    /** Widen four opaque digests and add a recipient-owned grant ledger without plaintext or keys. */
    public function up(): void
    {
        DB::statement('ALTER TABLE status_page_subscribers ALTER COLUMN email_hash TYPE varchar(128), ALTER COLUMN access_hash TYPE varchar(128)');
        DB::statement('ALTER TABLE notification_channels ALTER COLUMN verification_code_hash TYPE varchar(128)');
        DB::statement('ALTER TABLE notification_logs ALTER COLUMN destination_hash TYPE varchar(128)');
        Schema::create('status_subscription_link_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('status_page_id');
            $table->unsignedBigInteger('subscriber_id');
            $table->uuid('generation');
            $table->char('link_hash', 64)->unique();
            $table->index(['team_id', 'status_page_id', 'subscriber_id'], 'status_link_grants_owner_index');
            $table->foreign(['team_id', 'status_page_id', 'subscriber_id'], 'status_link_grants_subscriber_fk')
                ->references(['team_id', 'status_page_id', 'id'])->on('status_page_subscribers')->cascadeOnDelete();
        });
    }

    /** Keep widened columns on rollback: narrowing would truncate authenticated values and lose data. */
    public function down(): void
    {
        Schema::dropIfExists('status_subscription_link_grants');
    }
};
