<?php

declare(strict_types=1);

/** Reauthorize signed subscribers before delivery and serialize publication with membership removal. */

namespace App\Services\Broadcasting;

use Illuminate\Support\Facades\DB;
use JsonException;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Laravel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Laravel\Reverb\Protocols\Pusher\Server;

/** Never deliver a team frame using membership cached at subscription time. */
final class TeamPrivateChannel extends PrivateChannel
{
    /**
     * @param string $name Canonical native private-team channel name.
     * @param int $teamId Positive team identity validated by the channel manager.
     */
    public function __construct(string $name, private readonly int $teamId)
    {
        parent::__construct($name);
    }

    /**
     * Verify native HMAC integrity before trusting the signed actor identity and current membership.
     *
     * @param Connection $connection Socket whose identity is covered by the grant.
     * @param string|null $auth Native socket/channel/data HMAC; absent signatures deny admission.
     * @param string|null $data JSON containing only the authenticated actor's positive integer user_id.
     * @return bool True only for a verified current member, regardless of previously loaded user relations.
     * @throws ConnectionUnauthorized When the signature, signed identity or membership is invalid.
     * @throws \Illuminate\Database\QueryException When membership cannot be checked; no subscription is added.
     */
    protected function verify(Connection $connection, ?string $auth = null, ?string $data = null): bool
    {
        parent::verify($connection, $auth, $data);

        try {
            $identity = json_decode($data ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ConnectionUnauthorized();
        }

        if (
            !is_array($identity)
            || array_keys($identity) !== ['user_id']
            || !is_int($identity['user_id'])
            || $identity['user_id'] < 1
            || $this->currentMembers([$identity['user_id']]) === []
        ) {
            throw new ConnectionUnauthorized();
        }

        return true;
    }

    /**
     * Hold a shared aggregate lock until authorized frames enter the transport, fencing concurrent removals.
     *
     * @param array<string, mixed> $payload Native server publication, already authenticated by Reverb.
     * @param Connection|null $except Optional sender excluded by the native protocol.
     * @throws \Illuminate\Database\QueryException On database failure or lock contention; no team frame is sent.
     */
    public function broadcast(array $payload, ?Connection $except = null): void
    {
        DB::transaction(function () use ($payload, $except): void {
            // NOWAIT prevents a membership mutation from blocking Reverb's single event loop on this lock.
            $team = DB::table('teams')->where('id', $this->teamId)->lock('for share nowait')->first(['id']);
            $ids = [];

            foreach ($this->connections() as $subscription) {
                $userId = $subscription->data('user_id');

                if (is_int($userId) && $userId > 0) {
                    $ids[] = $userId;
                }
            }

            $members = $team === null ? [] : $this->currentMembers(array_values(array_unique($ids)));

            foreach ($this->connections() as $subscription) {
                if (!in_array($subscription->data('user_id'), $members, true)) {
                    // Native close removes every subscription before transport shutdown; reconnect must obtain new grants.
                    app(Server::class)->close($subscription->connection());
                }
            }

            if ($except === null) {
                parent::broadcastToAll($payload);
            } else {
                parent::broadcast($payload, $except);
            }
        });
    }

    /**
     * Cover direct and native internal publication paths with the same membership gate.
     *
     * @param array<string, mixed> $payload Native frame to deliver to the currently authorized subscribers.
     * @throws \Illuminate\Database\QueryException When current authority cannot be established.
     */
    public function broadcastToAll(array $payload): void
    {
        $this->broadcast($payload);
    }

    /**
     * @param list<int> $userIds Signed actor identities, bounded by this application's connection limit.
     * @return list<int> Fresh verified members of this explicit team; no tenant scope or selected preference is used.
     * @throws \Illuminate\Database\QueryException When the authoritative database is unavailable.
     */
    private function currentMembers(array $userIds): array
    {
        return array_values(
            DB::table('team_user')
                ->join('users', 'users.id', '=', 'team_user.user_id')
                ->where('team_user.team_id', $this->teamId)
                ->whereIn('team_user.user_id', $userIds)
                ->whereNotNull('users.email_verified_at')
                ->pluck('team_user.user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }
}
