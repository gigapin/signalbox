<?php

declare(strict_types=1);

/** Add optional daily channel silencing independently from encrypted provider credentials. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Existing channels have no quiet hours; partial and empty intervals are rejected by PostgreSQL. */
return new class () extends Migration {
    /** Add nullable local times and an explicit timezone; domain validation restricts timezone names to IANA. */
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->string('quiet_hours_start', 5)->nullable();
            $table->string('quiet_hours_end', 5)->nullable();
            $table->string('quiet_hours_timezone', 255)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE notification_channels ADD CONSTRAINT notification_channels_quiet_hours_check CHECK (
                (quiet_hours_start IS NULL AND quiet_hours_end IS NULL AND quiet_hours_timezone IS NULL)
                OR (
                    quiet_hours_start IS NOT NULL AND quiet_hours_end IS NOT NULL AND quiet_hours_timezone IS NOT NULL
                    AND quiet_hours_start ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
                    AND quiet_hours_end ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
                    AND quiet_hours_start <> quiet_hours_end
                    AND length(trim(quiet_hours_timezone)) > 0
                )
            );
            SQL);
    }

    /** Drop only silencing configuration; destinations, verification and delivery history remain intact. */
    public function down(): void
    {
        DB::statement('ALTER TABLE notification_channels DROP CONSTRAINT notification_channels_quiet_hours_check');
        Schema::table('notification_channels', function (Blueprint $table): void {
            $table->dropColumn(['quiet_hours_start', 'quiet_hours_end', 'quiet_hours_timezone']);
        });
    }
};
