<?php

declare(strict_types=1);

/** Replace Redis publication with deterministic scalar receipts and configurable broker outages. */

namespace Tests\Support;

use App\Contracts\Notifications\NotificationWorkPublisher;
use Carbon\CarbonImmutable;
use RuntimeException;

/** No recipient content or destination is stored, matching the production queue boundary. */
final class RecordingNotificationPublisher implements NotificationWorkPublisher
{
    /** @var list<array{team_id: int, delivery_id: int, available_at: CarbonImmutable}> Successful publications in order. */
    public array $sent = [];

    /** @var list<int> Teams whose broker publication must fail without blocking other teams. */
    public array $failTeams = [];

    /**
     * @param int $teamId Owning tenant.
     * @param int $deliveryId Committed delivery identity.
     * @param CarbonImmutable $availableAt Earliest attempt time.
     * @throws RuntimeException When publication for this team is deliberately refused.
     */
    public function publish(int $teamId, int $deliveryId, CarbonImmutable $availableAt): void
    {
        if (in_array($teamId, $this->failTeams, true)) {
            throw new RuntimeException('Synthetic broker password must never appear in logs.');
        }

        $this->sent[] = ['team_id' => $teamId, 'delivery_id' => $deliveryId, 'available_at' => $availableAt];
    }

    /** @return self Fresh publisher bound before incident listeners are resolved. */
    public static function bind(): self
    {
        $publisher = new self();
        app()->instance(NotificationWorkPublisher::class, $publisher);

        return $publisher;
    }
}
