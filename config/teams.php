<?php

declare(strict_types=1);

/**
 * Operational bounds of team membership flows. These are abuse limits shared by every entrypoint, not plan
 * entitlements (config/plans.php).
 */

return [
    // Invitation emails a team may send in a rolling 24-hour window, counting new invitations and resends. Each one
    // reaches an address nobody in the team has proved, so the per-minute route throttle alone is not enough.
    'invitations_per_team_per_day' => 50,
];
