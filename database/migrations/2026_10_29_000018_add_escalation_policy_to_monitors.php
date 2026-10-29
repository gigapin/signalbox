<?php

declare(strict_types=1);

/** Let a monitor override the team's default escalation policy. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The composite key keeps the policy inside the monitor's team; deleting the policy clears only the override,
 * so the monitor falls back to the team default.
 */
return new class () extends Migration {
    /** Add the nullable same-team override. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE monitors ADD COLUMN escalation_policy_id bigint NULL;
            ALTER TABLE monitors
                ADD CONSTRAINT monitors_escalation_policy_fk FOREIGN KEY (team_id, escalation_policy_id)
                    REFERENCES escalation_policies (team_id, id) ON DELETE SET NULL (escalation_policy_id);
            CREATE INDEX monitors_escalation_policy_idx ON monitors (team_id, escalation_policy_id)
                WHERE escalation_policy_id IS NOT NULL;
            SQL);
    }

    /** Remove the override; policies are unaffected. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE monitors DROP CONSTRAINT IF EXISTS monitors_escalation_policy_fk;
            ALTER TABLE monitors DROP COLUMN IF EXISTS escalation_policy_id;
            SQL);
    }
};
