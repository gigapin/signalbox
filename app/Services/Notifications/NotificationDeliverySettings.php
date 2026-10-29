<?php

declare(strict_types=1);

/** Validate persistent delivery retry budgets and coalescing bounds shared by planning and execution. */

namespace App\Services\Notifications;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/** Delivery retry attempts belong to this outbox, independently of Redis job deliveries or check observations. */
final readonly class NotificationDeliverySettings
{
    /** @var int Worker kill deadline in seconds; SMTP/HTTP execution must finish before this. */
    public const int WORKER_TIMEOUT = 60;

    /** @var int Persistent ownership seconds, above worker timeout and below Redis retry_after (90 s). */
    public const int LEASE_SECONDS = 75;

    /** @var int Maximum acquired provider attempts, including timed-out workers. */
    public int $maxAttempts;

    /** @var list<int> Retry delays in seconds after each failed attempt except the last. */
    public array $backoff;

    /** @var int Seconds allowed for rapid incident changes to coalesce before delivery. */
    public int $coalesceSeconds;

    /**
     * @param Repository $config Application-owned delivery policy; no tenant input controls these limits.
     * @throws LogicException When attempts, backoff or coalescing are unbounded or incoherent.
     */
    public function __construct(Repository $config)
    {
        $attempts = $config->get('notifications.delivery.max_attempts');
        $backoff = $config->get('notifications.delivery.backoff_seconds');
        $coalesce = $config->get('notifications.delivery.coalesce_seconds');

        if (!is_int($attempts) || $attempts < 1 || $attempts > 5
            || !is_array($backoff) || !array_is_list($backoff) || count($backoff) !== $attempts - 1
            || !is_int($coalesce) || $coalesce < 0 || $coalesce > 60) {
            throw new LogicException('The notification delivery policy is invalid.');
        }

        foreach ($backoff as $seconds) {
            if (!is_int($seconds) || $seconds < 1 || $seconds > 3600) {
                throw new LogicException('Notification retry backoff must be bounded seconds.');
            }
        }

        $this->maxAttempts = $attempts;
        $this->backoff = $backoff;
        $this->coalesceSeconds = $coalesce;
    }

    /**
     * @param int $attempts Provider attempts already acquired.
     * @return int Next retry delay; zero only when no further attempt is allowed.
     */
    public function retryDelay(int $attempts): int
    {
        return $this->backoff[$attempts - 1] ?? 0;
    }
}
