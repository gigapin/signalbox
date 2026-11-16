<?php

declare(strict_types=1);

/** Adapt Reverb's channel factory to enforce current membership on Signalbox private channels. */

namespace App\Services\Broadcasting;

use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Laravel\Reverb\Protocols\Pusher\Managers\ArrayChannelManager;

/** Preserve native application/channel storage while replacing the private team delivery boundary. */
final class TeamChannelManager extends ArrayChannelManager
{
    /**
     * @param string $channelName Native channel name; team IDs must be canonical positive PHP integers.
     * @return Channel Existing channel or the application-aware private channel for a team.
     * @throws ConnectionUnauthorized When a team channel contains an invalid identity.
     */
    public function findOrCreate(string $channelName): Channel
    {
        if (!str_starts_with($channelName, 'private-team.')) {
            return parent::findOrCreate($channelName);
        }

        if ($channel = $this->find($channelName)) {
            return $channel;
        }

        $identity = substr($channelName, strlen('private-team.'));
        $teamId = filter_var($identity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($teamId === false || (string) $teamId !== $identity) {
            throw new ConnectionUnauthorized();
        }

        $channel = new TeamPrivateChannel($channelName, $teamId);
        $this->applications[$this->application->id()][$channelName] = $channel;
        ChannelCreated::dispatch($channel);

        return $channel;
    }
}
