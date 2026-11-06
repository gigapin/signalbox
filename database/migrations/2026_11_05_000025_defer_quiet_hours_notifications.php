<?php

declare(strict_types=1);

/** Track recoverable quiet-hour waits and restore only legacy quiet-suppressed alerts for still-open incident cycles. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Recipient and lifecycle eligibility are checked again by the normal ledger before any restored delivery can send. */
return new class () extends Migration {
    /** Add the deferred boundary and recover the previous quiet-only suppression without changing other terminal outcomes. */
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->timestampTz('quiet_hours_deferred_until')->nullable();
        });

        DB::unprepared(<<<'SQL'
            UPDATE notification_logs AS deliveries
            SET status = 'pending', available_at = CURRENT_TIMESTAMP,
                quiet_hours_deferred_until = CURRENT_TIMESTAMP, claim_until = NULL,
                response = 'Restored after replacing terminal quiet-hours suppression with deferred delivery.',
                updated_at = CURRENT_TIMESTAMP
            FROM incidents, notification_channels AS channels
            WHERE deliveries.status = 'suppressed'
                AND deliveries.response = 'The channel is within its configured quiet hours.'
                AND deliveries.message_uuid IS NOT NULL
                AND deliveries.kind IN ('opened', 'escalated')
                AND incidents.id = deliveries.incident_id AND incidents.team_id = deliveries.team_id
                AND incidents.resolved_at IS NULL AND incidents.reopened_count = deliveries.cycle
                AND channels.id = deliveries.channel_id AND channels.team_id = deliveries.team_id
                AND channels.is_active AND channels.verified_at IS NOT NULL;
            SQL);
    }

    /** Remove scheduling metadata; preserve delivery history and any pending restored work. */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropColumn('quiet_hours_deferred_until');
        });
    }
};
