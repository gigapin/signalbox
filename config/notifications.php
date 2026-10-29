<?php

declare(strict_types=1);

/**
 * Operational bounds of notification channels, escalation policies and channel verification.
 * These are product safety limits shared by every entrypoint, not plan entitlements (config/plans.php).
 */

return [
    // Channels a team may configure; creation is refused beyond it under the Team lock.
    'channels_per_team' => 25,

    // Escalation policies a team may configure.
    'policies_per_team' => 10,

    // Steps of one escalation policy, each an absolute delay from the incident confirmation.
    'steps_per_policy' => 5,

    // Distinct channels notified by one step.
    'channels_per_step' => 10,

    // Longest step delay in minutes (24 hours).
    'max_step_delay_minutes' => 1440,

    // Lifetime of a verification code in seconds.
    'verification_ttl_seconds' => 900,

    // Wrong codes accepted before the pending code is discarded and a new one must be requested.
    'verification_max_attempts' => 5,

    // Minimum seconds between two verification messages for the same channel.
    'verification_resend_seconds' => 60,
];
