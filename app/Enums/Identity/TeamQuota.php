<?php

declare(strict_types=1);

/** Name the team-wide daily counters that bound messages sent to recipients the team has not proved it controls. */

namespace App\Enums\Identity;

/**
 * Each case is stored in `team_daily_quotas.quota` and reads its limit from configuration, so a new counter needs a
 * case and a configuration key but no change to the quota service.
 */
enum TeamQuota: string
{
    /** Verification messages sent to unverified notification destinations, whatever the channel type. */
    case ChannelVerification = 'channel_verification';

    /** Invitation emails, counting both new invitations and resends. */
    case Invitation = 'invitation';

    /** @return string Configuration key holding the maximum uses per rolling 24-hour window. */
    public function configKey(): string
    {
        return match ($this) {
            self::ChannelVerification => 'notifications.verification_sends_per_team_per_day',
            self::Invitation => 'teams.invitations_per_team_per_day',
        };
    }
}
