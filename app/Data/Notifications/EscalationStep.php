<?php

declare(strict_types=1);

/** Describe one escalation step: after a delay, notify a set of channels. */

namespace App\Data\Notifications;

/** Delays are absolute from the incident confirmation, so steps of one policy are strictly increasing. */
final readonly class EscalationStep
{
    /**
     * @param int $afterMinutes Minutes after confirmation, from zero to the configured maximum.
     * @param list<int> $channelIds Distinct channels of the same team, in submission order.
     */
    public function __construct(public int $afterMinutes, public array $channelIds)
    {
    }

    /** @return array{after_minutes: int, channel_ids: list<int>} Persisted `steps_json` element. */
    public function toArray(): array
    {
        return ['after_minutes' => $this->afterMinutes, 'channel_ids' => $this->channelIds];
    }
}
